<?php
/**
 * ownCloud
 *
 * @author Jörn Friedrich Dreyer <jfd@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright (C) 2014-2017 ownCloud, GmbH.
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files_Primary_S3;

use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\Exception\S3Exception;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\StreamWrapper;
use OC;
use OC\ServiceUnavailableException;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\ObjectStore\IVersionedObjectStorage;
use OCP\Files\ObjectStore\ObjectStoreOperationException;
use OCP\Files\ObjectStore\ObjectStoreWriteException;
use Psr\Http\Message\RequestInterface;
use function array_filter;
use function array_map;
use function array_values;
use function fclose;
use function is_resource;
use function rawurlencode;
use function rewind;
use function stream_get_meta_data;

require_once __DIR__ . '/bootstrap.php';
loadComposerDependencies();

class S3Storage implements IObjectStore, IVersionedObjectStorage {
	private ?S3Client $connection = null;
	private ?S3Client $downConnection = null;

	/**
	 * @throws Exception
	 */
	public function __construct(private readonly array $params) {
		assertComposerDependencies();
		if (!isset($this->params['options'], $this->params['bucket'])) {
			throw new Exception($this->t('Connection options and bucket must be configured.'));
		}
	}

	/**
	 * @throws ServiceUnavailableException
	 * @throws Exception
	 */
	protected function init(): void {
		if ($this->connection) {
			return;
		}
		$config = $this->params['options'];
		$config['http_handler'] = $this->getHandlerV7(false); // CurlMultiHandler for uploads
		$this->connection = new S3Client($config);

		// replace the http_handler for the download connection
		$config['http_handler'] = $this->getHandlerV7(true); // StreamHandler for downloads
		$this->downConnection = new S3Client($config);
		try {
			$this->connection->listBuckets();
		} catch (S3Exception $exception) {
			OC::$server->getLogger()->logException($exception);
			throw new ServiceUnavailableException($this->t('No S3 ObjectStore available'));
		}

		if (!$this->connection->doesBucketExist($this->getBucket())) {
			throw new Exception($this->t('Bucket <%s> does not exist.', [$this->getBucket()]));
		}
	}

	private function getHandlerV7(bool $isStream): GuzzleHandler {
		// Create a handler stack that has all the default middlewares attached
		$handler = HandlerStack::create($isStream ? new StreamHandler() : new CurlMultiHandler());

		$requestFunc = static function (RequestInterface $request) {
			if ($request->getMethod() !== 'PUT') {
				return $request;
			}
			$body = $request->getBody();
			if ($body->getSize() !== 0) {
				return $request;
			}
			if ($request->hasHeader('Content-Length')) {
				return $request;
			}
			// force content length header on empty body
			return $request->withHeader('Content-Length', '0');
		};
		// Push the handler onto the handler stack
		$handler->push(Middleware::mapRequest($requestFunc));
		// Inject the handler into the client
		$client = new Client(['handler' => $handler]);
		return new GuzzleHandler($client);
	}

	#[\Override]
	public function getStorageId() {
		return $this->params['bucket'];
	}

	#[\Override]
	public function writeObject($urn, $stream) {
		$this->init();

		$this->upload($urn, $stream);
	}

	#[\Override]
	public function deleteObject($urn) {
		$this->init();
		try {
			$this->connection->deleteObject([
				'Bucket' => $this->getBucket(),
				'Key' => $urn,
			]);
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	#[\Override]
	public function readObject($urn) {
		$this->init();
		try {
			$stream = new LazyReadStream($this->downConnection, $this->getBucket(), $urn);
			return StreamWrapper::getResource($stream);
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	private function getBucket(): string {
		return $this->params['bucket'];
	}

	/**
	 * List all versions for the given file
	 *
	 * @param string $urn the unified resource name used to identify the object
	 * @return array
	 * @throws ObjectStoreOperationException
	 * @throws ServiceUnavailableException
	 */
	#[\Override]
	public function getVersions($urn): array {
		$this->init();
		try {
			$list = $this->connection->listObjectVersions([
				'Bucket' => $this->getBucket(),
				'Prefix' => $urn
			]);
			// Phan does not understand that $list['Versions'] contains an array.
			/* @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
			$versions = array_filter($list['Versions'] ?? [], static function ($v) use ($urn) {
				return ($v['Key'] === $urn) && $v['IsLatest'] !== true;
			});
			$versions = array_map(static function ($version) {
				return [
					'version' => $version['VersionId'],
					'timestamp' => $version['LastModified']->getTimestamp(),
					'oid' => $version['Key'],
					'etag' => $version['ETag'],
					'size' => (int)$version['Size'],
				];
			}, $versions);
			return array_values($versions);
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	/**
	 * Get one explicit version for the given file
	 *
	 * @param string $urn the unified resource name used to identify the object
	 * @param string $versionId
	 * @return array
	 * @throws ObjectStoreOperationException
	 * @throws ServiceUnavailableException
	 */
	#[\Override]
	public function getVersion($urn, $versionId): array {
		$this->init();
		try {
			$result = $this->connection->headObject([
				'Bucket' => $this->getBucket(),
				'Key' => $urn,
				'VersionId' => $versionId,
			]);

			return [
				'version' => $versionId,
				'timestamp' => $result['LastModified']->getTimestamp(),
				'oid' => $urn,
				'etag' => $result['ETag'],
				'size' => (int)$result['ContentLength'],
			];
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	/**
	 * Get the content of a given version of a given file as stream resource
	 *
	 * @param string $urn the unified resource name used to identify the object
	 * @param string $versionId
	 * @return resource
	 * @throws ObjectStoreOperationException
	 * @throws ServiceUnavailableException
	 */
	#[\Override]
	public function getContentOfVersion($urn, $versionId) {
		$this->init();
		try {
			$stream = new LazyReadStream($this->downConnection, $this->getBucket(), $urn, $versionId);
			return StreamWrapper::getResource($stream);
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	/**
	 * Restore the given version of a given file
	 *
	 * @param string $urn the unified resource name used to identify the object
	 * @param string $versionId
	 * @return boolean
	 * @throws ObjectStoreOperationException
	 * @throws ServiceUnavailableException
	 */
	#[\Override]
	public function restoreVersion($urn, $versionId): bool {
		$this->init();
		try {
			$this->connection->copyObject([
				'Bucket' => $this->getBucket(),
				'Key' => $urn,
				'CopySource' => "/{$this->getBucket()}/" . rawurlencode($urn) . "?versionId=$versionId"
			]);

			return true;
		} catch (AwsException $ex) {
			throw new ObjectStoreOperationException($ex->getAwsErrorMessage(), $ex->getStatusCode(), $ex);
		}
	}

	/**
	 * Tells the storage to explicitly create a version of a given file.
	 * In a versioned bucket the versions are created automatically,
	 * so this is a no-op.
	 */
	#[\Override]
	public function saveVersion($internalPath): bool {
		return true;
	}

	private function t(string $text, array $parameters = []): string {
		return (string)OC::$server->getL10N('files_primary_s3')
			->t($text, $parameters);
	}

	/**
	 * @param resource $stream
	 * @throws ObjectStoreWriteException
	 */
	private function upload(string $urn, $stream, bool $retry = true): void {
		$opt = [];
		if (isset($this->params['serversideencryption'])) {
			$opt['ServerSideEncryption'] = $this->params['serversideencryption'];
		}
		if (isset($this->params['part_size'])) {
			$opt['part_size'] = $this->params['part_size'];
		}
		if (isset($this->params['concurrency'])) {
			$opt['concurrency'] = $this->params['concurrency'];
		}

		$uploader = new ObjectUploader($this->connection, $this->getBucket(), $urn, $stream, 'private', $opt);

		try {
			$uploader->upload();
		} catch (AwsException $e) {
			/**
			 * If the error is from AwsException then just wrap the aws error message
			 * to our exception.
			 */
			throw new ObjectStoreWriteException($e->getAwsErrorMessage(), $e->getStatusCode(), $e);
		} catch (MultipartUploadException $e) {
			// BackBlaze B2 - retry the upload once on transient 5xx
			// (https://www.backblaze.com/blog/b2-503-500-server-error/).
			// We do not get an explicit status code here, so we match the message.
			if ($retry && str_contains($e->getMessage(), 'Please retry your upload')) {
				OC::$server->getLogger()->logException($e, [
					'message' => 'B2 retrying upload.'
				]);
				if (is_resource($stream) && (stream_get_meta_data($stream)['seekable'] ?? false)) {
					rewind($stream);
				}
				$this->upload($urn, $stream, false);
				return;
			}
			/**
			 * There can be multiple parts that might have failed to upload. So it would be
			 * better to have a custom message here. The getMessage throws all the parts which
			 * are failed.
			 */
			OC::$server->getLogger()->logException($e);
			$message = $this->t('Upload failed. Please ask you administrator to have a look at the log files for more details.');
			throw new ObjectStoreWriteException($message, $e->getCode(), $e);
		}

		if (\is_resource($stream)) {
			fclose($stream);
		}
	}
}

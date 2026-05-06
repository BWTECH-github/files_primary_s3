<?php
/**
 * @copyright Copyright (c) 2024, ownCloud GmbH
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license GPL-2.0
 */

namespace OCA\Files_Primary_S3;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use function strlen;

class LazyReadStream implements StreamInterface {
	use StreamDecoratorTrait;

	private readonly int $size;
	private int $offset = 0;

	/** @phpstan-ignore-next-line */
	private StreamInterface $stream;

	public function __construct(
		private readonly S3Client $client,
		private readonly string $bucket,
		private readonly string $key,
		private readonly ?string $versionId = null,
	) {
		$this->resetStream();

		// Eagerly fetch the size — also acts as access/auth probe.
		$result = $this->client->headObject([
			'Bucket'    => $this->bucket,
			'Key'       => $this->key,
			'VersionId' => $this->versionId,
		]);
		$this->size = (int) $result['ContentLength'];
	}

	protected function createStream(): StreamInterface {
		$options = [
			'Bucket'    => $this->bucket,
			'Key'       => $this->key,
			'VersionId' => $this->versionId,
			'seekable'  => true,
		];
		if ($this->offset > 0) {
			$options['Range'] = "bytes=$this->offset-";
		}
		$command = $this->client->getCommand('GetObject', $options);
		$command['@http']['stream'] = true;
		$result = $this->client->execute($command);

		/* @phan-suppress-next-line PhanTypeMismatchReturn */
		return $result['Body'];
	}

	#[\Override]
	public function getSize(): ?int {
		return $this->size;
	}

	#[\Override]
	public function seek($offset, $whence = SEEK_SET): void {
		$this->offset = match ($whence) {
			SEEK_SET => $offset,
			SEEK_END => $offset + $this->size,
			SEEK_CUR => $this->offset + $offset,
			default  => $this->offset,
		};
		$this->resetStream();
	}

	#[\Override]
	public function isReadable(): bool {
		// A successful HEAD in the constructor proves we can read.
		return true;
	}

	#[\Override]
	public function isSeekable(): bool {
		return true;
	}

	#[\Override]
	public function isWritable(): bool {
		return false;
	}

	#[\Override]
	public function tell(): int {
		return $this->offset;
	}

	#[\Override]
	public function eof(): bool {
		return isset($this->stream) && $this->stream->eof();
	}

	#[\Override]
	public function read($length): string {
		$data = $this->stream->read($length);
		$this->offset += strlen($data);
		return $data;
	}

	private function resetStream(): void {
		// unsetting the property forces the next access to go through __get().
		unset($this->stream);
	}
}

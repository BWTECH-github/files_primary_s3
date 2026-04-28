<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
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

namespace OCA\Files_Primary_S3\Command;

use Aws\S3\S3Client;
use InvalidArgumentException;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

class s3List extends Command {
	public function __construct(private readonly IConfig $config) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		$this
			->setName('s3:list')
			->setDescription('List objects, buckets or versions of an object')
			->addArgument('bucket', InputArgument::OPTIONAL, 'Name of the bucket; its objects will be listed')
			->addArgument('object', InputArgument::OPTIONAL, 'Key of the object; its versions will be listed');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$client = $this->getClient();

		$bucketName = $input->getArgument('bucket');
		if ($bucketName === null) {
			$result = $client->listBuckets();
			$buckets = \array_map(static function ($bucket) use ($client) {
				$versionStatus = $client->getBucketVersioning([
					'Bucket' => $bucket['Name'],
				]);
				$bucket['Versioning'] = $versionStatus['Status'];
				$corsConfig = $client->getBucketCors([
					'Bucket' => $bucket['Name'],
				]);
				$bucket['CORS'] = $corsConfig['CORSRules'];
				return $bucket;
			}, $result['Buckets']);
			$this->printValue($output, $buckets, ['Name', 'Versioning', 'CORS']);
		} else {
			$object = $input->getArgument('object');
			if ($object === null) {
				$result = $client->listObjects([
					'Bucket' => $bucketName,
				]);
				$this->printValue($output, $result['Contents'], ['Key', 'LastModified', 'ETag', 'Size']);
			} else {
				$result = $client->listObjectVersions([
					'Bucket' => $bucketName,
					'Prefix' => $object
				]);
				$versions = \array_filter($result['Versions'] ?? [], static fn ($version) => $version['Key'] === $object);
				$this->printValue($output, $versions, ['Key', 'LastModified', 'ETag', 'Size', 'VersionId', 'IsLatest']);

				$output->writeln('Delete Markers:');
				$output->writeln('----------------------------------------');
				$markers = \array_filter($result['DeleteMarkers'] ?? [], static fn ($marker) => $marker['Key'] === $object);
				$this->printValue($output, $markers, ['Key', 'LastModified', 'VersionId', 'IsLatest']);
			}
		}
		return 0;
	}

	private function getClient(): S3Client {
		$cfg = $this->config->getSystemValue('objectstore_multibucket', null);
		$cfg = $this->config->getSystemValue('objectstore', $cfg);
		if ($cfg === null) {
			throw new InvalidArgumentException('No object store is configured.');
		}
		/* @phan-suppress-next-line PhanDeprecatedFunction */
		return S3Client::factory($cfg['arguments']['options']);
	}

	protected function printValue(OutputInterface $output, array $results, array $keys): void {
		foreach ($results as $result) {
			foreach ($keys as $key) {
				$value = isset($result[$key]) ? \json_encode($result[$key]) : '---';
				$output->writeln("$key: $value");
			}
			$output->writeln('----------------------------------------');
		}
	}
}

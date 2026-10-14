<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0-only
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
 */

namespace OCA\Files_Primary_S3\Tests\Unit\Command;

use Aws\MockHandler;
use Aws\Result;
use OCA\Files_Primary_S3\Command\s3List;
use OCP\IConfig;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

/**
 * `occ s3:list` gegen einen echten S3Client, dessen Antworten aus dem
 * MockHandler des AWS-SDK kommen – kein Netz, kein MinIO.
 *
 * Das SDK lässt bei leeren Listen den Schlüssel schlicht weg: ein leerer
 * Bucket liefert kein `Contents`, ein Speicher ohne Buckets kein `Buckets`,
 * ein Objekt ohne Versionen kein `Versions`. Der Zugriff auf einen fehlenden
 * Schlüssel des Ergebnisses ergibt `null`, nicht `[]` – genau das muss das
 * Kommando aushalten.
 */
class S3ListTest extends TestCase {
	private MockHandler $s3;

	protected function setUp(): void {
		parent::setUp();
		// Das AWS-SDK liegt im vendor/ der App und wird sonst erst mit dem Kommando geladen – hier vorab.
		require_once __DIR__ . '/../../../lib/bootstrap.php';
		\OCA\Files_Primary_S3\loadComposerDependencies();
		$this->s3 = new MockHandler();
	}

	/**
	 * Führt `occ s3:list` mit den gegebenen Argumenten aus. Der S3Client
	 * entsteht wie im Betrieb aus `objectstore.arguments.options` – nur mit
	 * dem MockHandler als Transport.
	 */
	private function runList(array $arguments): CommandTester {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')
			->willReturnCallback(function (string $key, $default = '') {
				if ($key === 'objectstore') {
					return [
						'class' => 'OCA\\Files_Primary_S3\\S3Storage',
						'arguments' => [
							'options' => [
								'version' => 'latest',
								'region' => 'eu-central-1',
								'endpoint' => 'http://s3.invalid',
								'use_path_style_endpoint' => true,
								'credentials' => ['key' => 'test', 'secret' => 'test'],
								'handler' => $this->s3,
							],
						],
					];
				}
				return $default;
			});

		$tester = new CommandTester(new s3List($config));
		$tester->execute($arguments);
		return $tester;
	}

	public function testAnEmptyBucketListsNothingInsteadOfCrashing(): void {
		// Antwort auf ListObjects für einen leeren Bucket: kein Schlüssel "Contents"
		$this->s3->append(new Result(['Name' => 'leer', 'IsTruncated' => false, 'MaxKeys' => 1000]));

		$tester = $this->runList(['bucket' => 'leer']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay(true));
		$this->assertSame('ListObjects', $this->s3->getLastCommand()->getName());
		$this->assertSame('leer', $this->s3->getLastCommand()['Bucket']);
	}

	public function testObjectsOfABucketArePrintedWithTheirFields(): void {
		$this->s3->append(new Result([
			'Name' => 'voll',
			'Contents' => [
				['Key' => 'a.txt', 'LastModified' => '2026-10-14T10:00:00+00:00', 'ETag' => '"d41d8"', 'Size' => 3],
				['Key' => 'b.txt', 'Size' => 0],
			],
		]));

		$tester = $this->runList(['bucket' => 'voll']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame(
			"Key: \"a.txt\"\n"
			. "LastModified: \"2026-10-14T10:00:00+00:00\"\n"
			. "ETag: \"\\\"d41d8\\\"\"\n"
			. "Size: 3\n"
			. "----------------------------------------\n"
			. "Key: \"b.txt\"\n"
			. "LastModified: ---\n"
			. "ETag: ---\n"
			. "Size: 0\n"
			. "----------------------------------------\n",
			$tester->getDisplay(true)
		);
	}

	public function testAStoreWithoutBucketsListsNothingInsteadOfCrashing(): void {
		// Antwort auf ListBuckets ohne einen einzigen Bucket: kein Schlüssel "Buckets"
		$this->s3->append(new Result(['Owner' => ['ID' => 'test']]));

		$tester = $this->runList([]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay(true));
		$this->assertSame('ListBuckets', $this->s3->getLastCommand()->getName());
	}

	public function testAnObjectWithoutVersionsPrintsOnlyTheHeadings(): void {
		// Antwort auf ListObjectVersions ohne Treffer: weder "Versions" noch "DeleteMarkers"
		$this->s3->append(new Result(['Name' => 'leer', 'Prefix' => 'fehlt.txt']));

		$tester = $this->runList(['bucket' => 'leer', 'object' => 'fehlt.txt']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame(
			"Delete Markers:\n"
			. "----------------------------------------\n",
			$tester->getDisplay(true)
		);
	}
}

<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0-only
 */

namespace OCA\Files_Primary_S3;

use RuntimeException;

function loadComposerDependencies(): void {
	$autoload = __DIR__ . '/../vendor/autoload.php';
	if (\is_file($autoload)) {
		require_once $autoload;
	}
}

function assertComposerDependencies(): void {
	if (!\class_exists(\Aws\S3\S3Client::class)) {
		throw new RuntimeException(
			'S3 Primary Object Storage dependencies are missing. Run composer install --no-dev --optimize-autoloader in the files_primary_s3 app directory.'
		);
	}
}

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

	// Guzzle steht in composer.json unter 'replace': es kommt aus dem Kern,
	// nicht aus dem vendor/ dieser App. Fehlt es oder ist es eine andere
	// Hauptfassung, laeuft das AWS-SDK irgendwo tief im Innern gegen eine
	// Wand - mit einer Fehlermeldung, aus der niemand die Ursache liest.
	// Deshalb hier, einmal, im Klartext.
	if (!\interface_exists(\GuzzleHttp\ClientInterface::class)) {
		throw new RuntimeException(
			'Guzzle is missing. files_primary_s3 declares guzzlehttp/guzzle under "replace" '
			. 'because owncloud.online core provides it; this installation does not. '
			. 'Check lib/composer/guzzlehttp in the core directory.'
		);
	}

	$major = \defined('\GuzzleHttp\ClientInterface::MAJOR_VERSION')
		? \constant('\GuzzleHttp\ClientInterface::MAJOR_VERSION')
		: null;
	if ($major !== null && (int)$major < 7) {
		throw new RuntimeException(
			'Guzzle ' . (int)$major . ' is too old for the bundled AWS SDK, which needs 7.4.5 or newer. '
			. 'files_primary_s3 takes guzzlehttp/guzzle from owncloud.online core - upgrade it there.'
		);
	}
}

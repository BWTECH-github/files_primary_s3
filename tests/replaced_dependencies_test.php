<?php

declare(strict_types=1);

/**
 * @author BW-Tech GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0-only
 *
 * files_primary_s3 fuehrt Guzzle und die PSR-HTTP-Pakete unter 'replace'. Das
 * heisst: diese App behauptet, sie liefere sie - tatsaechlich liefert sie der
 * Kern. Die Nummern in composer.json sind damit eine Zusicherung ueber einen
 * fremden Baum, und solche Zusicherungen laufen auseinander, ohne dass es
 * jemand merkt.
 *
 * Zwei Folgen hat das:
 *   1. 'composer audit' dieser App sieht die ersetzten Pakete nie. Eine
 *      Schwachstelle in Guzzle faellt hier also nicht auf.
 *   2. Stimmen die Nummern nicht mehr, ist die Zusicherung schlicht falsch -
 *      composer haelt eine Abhaengigkeit fuer erfuellt, die es nicht ist.
 *
 * Dieser Test prueft beides, soweit es ohne Netz geht.
 *
 *   php8.4 tests/replaced_dependencies_test.php [pfad/zum/core]
 *
 * Ohne Argument wird der Kern an den ueblichen Stellen gesucht; wird er nicht
 * gefunden, laeuft der Test ohne den Abgleich weiter und sagt das.
 */

$appRoot = \dirname(__DIR__);
$failures = 0;

$manifest = \json_decode((string)\file_get_contents($appRoot . '/composer.json'), true);
if (!\is_array($manifest)) {
	echo "FAILED: composer.json is not readable JSON\n";
	exit(1);
}

$replace = $manifest['replace'] ?? [];
if ($replace === []) {
	echo "FAILED: composer.json declares no 'replace' block - has the pattern changed?\n";
	exit(1);
}

// --- 1. Jede Ersetzung ist genau eine Fassung ------------------------------
// Ein Bereich ('^7.14') waere bequem, verdeckt aber genau das, worum es hier
// geht: er bleibt jahrelang formal richtig, waehrend der Kern sich darunter
// bewegt. Eine feste Nummer zwingt dazu, den Abgleich zu fuehren.
foreach ($replace as $package => $constraint) {
	if (\preg_match('/^\d+\.\d+(\.\d+)?$/', (string)$constraint) !== 1) {
		echo 'FAILED: ' . $package . ' is replaced with "' . $constraint
			. '", which is a range. Ranges hide drift against core - pin the exact version core ships.' . "\n";
		$failures++;
	}
}

// --- 2. Abgleich mit dem, was der Kern tatsaechlich mitbringt --------------
$coreRoot = $argv[1] ?? findCoreRoot($appRoot);

if ($coreRoot === null) {
	echo "Note: no core checkout found, skipping the comparison against core/composer.lock.\n";
	echo "      Pass the path as the first argument to run it.\n";
} else {
	$lockFile = \rtrim($coreRoot, '/') . '/composer.lock';
	$lock = \json_decode((string)\file_get_contents($lockFile), true);
	if (!\is_array($lock)) {
		echo 'FAILED: ' . $lockFile . " is not readable JSON\n";
		exit(1);
	}

	$shipped = [];
	foreach (\array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
		$shipped[$package['name']] = \ltrim((string)$package['version'], 'v');
	}

	foreach ($replace as $package => $constraint) {
		if (!isset($shipped[$package])) {
			echo 'FAILED: ' . $package . ' is replaced here but core does not ship it at all.'
				. " Nothing would provide it at runtime.\n";
			$failures++;
			continue;
		}
		if ($shipped[$package] !== $constraint) {
			echo 'FAILED: ' . $package . ' is replaced with ' . $constraint
				. ' but core ships ' . $shipped[$package] . ". Update the replace block.\n";
			$failures++;
			continue;
		}
		echo '  ' . \str_pad($package, 24) . ' ' . $constraint . " matches core\n";
	}
}

// --- 3. Was das AWS-SDK wirklich verlangt ----------------------------------
// Die Ersetzung darf nicht unter die Anforderung des SDK rutschen: composer
// wuerde das durchwinken, das SDK bricht dann erst zur Laufzeit.
$appLock = \json_decode((string)\file_get_contents($appRoot . '/composer.lock'), true);
if (\is_array($appLock)) {
	foreach ($appLock['packages'] ?? [] as $package) {
		if ($package['name'] !== 'aws/aws-sdk-php') {
			continue;
		}
		foreach ($package['require'] ?? [] as $needed => $constraint) {
			if (!isset($replace[$needed])) {
				continue;
			}
			if (!satisfies($replace[$needed], (string)$constraint)) {
				echo 'FAILED: aws-sdk-php needs ' . $needed . ' ' . $constraint
					. ' but the replace block promises ' . $replace[$needed] . ".\n";
				$failures++;
			} else {
				echo '  ' . \str_pad($needed, 24) . ' ' . $replace[$needed]
					. ' satisfies aws-sdk-php ' . $constraint . "\n";
			}
		}
	}
}

if ($failures === 0) {
	echo "Replaced dependencies OK (" . \count($replace) . " packages)\n";
}

exit($failures === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

function findCoreRoot(string $appRoot): ?string {
	$candidates = [
		$appRoot . '/../..',          // apps/files_primary_s3 im Kern
		$appRoot . '/../owncloud.online.DEV',
		$appRoot . '/../../owncloud.online.DEV',
	];
	foreach ($candidates as $candidate) {
		if (\is_file($candidate . '/composer.lock') && \is_file($candidate . '/lib/base.php')) {
			return (string)\realpath($candidate);
		}
	}

	return null;
}

/**
 * Reicht die feste Fassung fuer eine composer-Anforderung? Deckt die Formen
 * ab, die in diesen Sperrdateien vorkommen: '^x.y.z', '>=x.y' und Alternativen
 * mit '||'.
 */
function satisfies(string $version, string $constraint): bool {
	foreach (\explode('||', $constraint) as $alternative) {
		if (satisfiesSingle($version, \trim($alternative))) {
			return true;
		}
	}

	return false;
}

function satisfiesSingle(string $version, string $constraint): bool {
	$constraint = \ltrim($constraint, 'v');
	if ($constraint === '*' || $constraint === '') {
		return true;
	}

	if ($constraint[0] === '^') {
		$min = \substr($constraint, 1);
		if (\version_compare($version, $min, '<')) {
			return false;
		}
		// Caret bindet die erste Stelle ungleich null.
		$minParts = \explode('.', $min);
		$verParts = \explode('.', $version);
		if (($minParts[0] ?? '0') !== '0') {
			return ($verParts[0] ?? '') === $minParts[0];
		}

		return ($verParts[0] ?? '') === ($minParts[0] ?? '')
			&& ($verParts[1] ?? '') === ($minParts[1] ?? '');
	}

	if (\str_starts_with($constraint, '>=')) {
		return \version_compare($version, \ltrim(\substr($constraint, 2), 'v '), '>=');
	}

	return $version === $constraint;
}

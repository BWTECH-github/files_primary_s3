# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [2.0.0] - 2026-09-17

Durchgang für die Redesign-Oberfläche von owncloud.online 11.1. Betrieb mit S3
als Primärspeicher auf dem Redesign-Kern belegt (MinIO: Hochladen und
Herunterladen über WebDAV mit gleicher Prüfsumme, `occ s3:list`,
`occ s3:create-bucket`). Läuft weiter ab owncloud.online 11.

### Fixed

- Die Verwaltungsseite „Verschlüsselung“ bekam eine zweite Karte mit derselben
  Kennung wie die Kernkarte (`#encryptionAPI`); nach dem Umhängen des Hinweises
  blieb sie leer stehen. Jetzt rendert die App nur den Hinweis mit eigener
  Kennung, das Skript setzt ihn vor den Schalter und entfernt den leeren Rahmen.
- Das Skript verschob jede Warnung der Seite vor den Schalter, auch die anderer
  Apps. Jetzt nur den eigenen Hinweis.
- Der gesperrte Schalter nennt den Hinweis als Beschreibung
  (`aria-describedby`); das Ausblenden des Standard-Moduls entfällt.
- Deutsche Texte: Hinweis mit dem Begriff der Kernkarte („serverseitige
  Verschlüsselung“), fehlender Eintrag in de_CH, grammatisch falscher
  Upload-Fehler (de, de_CH, de_DE); de_AT neu.

### Added

- `tests/visual/pruefe-verschluesselungskarte.js`: Browser-Probe für die
  Verschlüsselungskarte (braucht eine Instanz mit `objectstore`).

## [1.6.4] - 2026-08-13

### Changed

- Produktname, Beschreibung und übersetzte Zeichenketten nennen owncloud.online;
  Verweise auf Fehlerbereich, Repository und Dokumentation zeigen auf das eigene
  Repository. Screenshots aus fremden Repositories entfernt.

## [Unreleased]

### Changed

- Forked for the [owncloud.online](https://github.com/BWTECH-github/owncloud.online) distribution; maintained by BW-Tech GmbH.
- Bumped minimum PHP version to **8.4** (composer, info.xml, CI).
- Modernised codebase to PHP 8.4 idioms: constructor property promotion, `readonly` properties, typed properties, `#[\Override]` attributes, `match` expression, null-coalescing on optional S3 list responses.
- Updated app metadata (`appinfo/info.xml`, `composer.json`, `README.md`) to reflect the BW-Tech fork.

### Notes

- Public API of `S3Storage`, `LazyReadStream`, OCC commands and the admin panel is unchanged — drop-in compatible with the upstream owncloud.online server.

## [1.6.3] - 2026-07-30

### Fixed

- **Critical:** resolved a fatal class-shadowing conflict on owncloud.online 11.0.11
  (`Call to undefined method GuzzleHttp\Psr7\Utils::caselessEquals()`, and grey preview
  tiles from `StreamWrapper::getSource`). The app bundled its own copy of the Guzzle HTTP
  stack (guzzle 7.10 / psr7 2.9). Since the server upgraded Guzzle to 7.15 / psr7 2.13,
  the two copies collided in the shared class space and produced an inconsistent
  "Frankenstein" Guzzle whenever S3 was used as primary object store, breaking every
  `occ` call and preview generation.

### Changed

- The app no longer bundles libraries that the owncloud.online server already provides
  (`guzzlehttp/guzzle`, `guzzlehttp/psr7`, `guzzlehttp/promises`, `psr/http-message`,
  `psr/http-client`, `psr/http-factory`). These are now declared via `composer replace`
  so the app always uses the server's copy. This permanently prevents version-skew
  conflicts on future server Guzzle bumps and shrinks the app package. Only AWS-specific
  dependencies (`aws/aws-sdk-php`, `aws/aws-crt-php`, `mtdowling/jmespath.php`,
  `symfony/*`) remain vendored.

## [1.6.1] - 2026-04-07

### Changed

- Upstream #697 - update aws-sdk-php to 3.337.3


## [1.6.0]  - 2024-10-25

### Added

- Upstream #679 - feat: BackBlaze B2 upload retry logic
- Upstream #674 - feat: support seeking on LazyReadStream

## [1.5.0]  - 2023-07-27

### Changed

- Upstream #664 - Always return an int from Symfony Command execute method
- Minimum core version 10.11, minimum php version 7.4


## [1.4.0]  - 2022-10-25

### Changed

- Upstream #605 - Allow configurable concurrent uploads

### Fixed

- Upstream #618 - Fix stream download release


## [1.3.0] - 2022-08-10

### Changed

- Upstream core #39387 - Update guzzle major version to 7
- This version is compatible with both upstream server versions 10.10 and 10.11.0

## [1.2.0] - 2021-12-29

### Changed

- Create a seekable stream when reading. Allows http range requests - Upstream #522
- Update info.xml - Upstream #495

## [1.1.3] - 2021-11-09

### Fixed

- Prohibit enabling encryption when S3 Object Storage is configured - Upstream #487


## [1.1.2] - 2020-04-22

### Fixed

- Bugfix/l10n - Upstream #323

### Changed

- Update PHP dependencies - Upstream #316

### Added

- Add phpdoc for Symfony Command execute - Upstream #309
- Add l10n to dist/appstore build - Upstream #340

## [1.1.1] - 2020-01-23

### Fixed

- Catch Multipart exception when uploading large files - Upstream #304

## [1.1.0] - 2019-12-23

### Fixed

- Format message thrown by AWS exception - Upstream #287

### Removed

- Remove support for PHP 7.0 - Upstream #277

## [1.0.4] - 2019-09-23

### Fixed

- Proper handling of objecstorage issues on object upload - Upstream #212

### Changed

- Various library updates (`aws/aws-sdk-php`, `guzzlehttp/psr7`, `ralouphie/getallheaders` ) - Upstream #231, #236, #241

## [1.0.3] - 2019-01-09

### Fixed

- Fix Makefile to use GNU tar to prevent extraction issues on some envs - Upstream #173

## [1.0.2] - 2018-12-07

### Changed

- Set max version to 10 because core is switching to Semver

## 1.0.0 - 2018-07-20

### Changed

- First marketplace release

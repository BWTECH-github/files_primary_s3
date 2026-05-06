# files_primary_s3

**S3-compatible object storage as primary storage for [owncloud.online](https://github.com/BWTECH-github/owncloud.online).**

A fork of the original [owncloud/files_primary_s3](https://github.com/owncloud/files_primary_s3) app, updated for **PHP 8.4** and maintained as part of the owncloud.online distribution by [BW.Tech](https://bw.tech).

When this app is enabled and configured, **every user file** that ownCloud writes goes into an S3 bucket instead of the local filesystem. Metadata (filenames, shares, permissions) stays in the database; the file *contents* live in S3.

---

## Features

- **S3 multi-part upload** — files larger than 5 GB are split and uploaded in parallel parts
- **S3 versioning** — previous versions of a file are restorable from the bucket's native version history
- **Streaming reads** with HTTP Range support — random-access on large objects without re-downloading
- **BackBlaze B2 retry logic** — transient 5xx errors during multipart upload are retried once automatically
- **Server-side encryption** (optional, e.g. `AES256`)
- **Configurable part size and upload concurrency**

Tested against AWS S3, Ceph RGW, Scality RING, MinIO, BackBlaze B2, and Wasabi-compatible S3 endpoints.

---

## Requirements

| Component | Version |
|---|---|
| owncloud.online server | 11.x |
| PHP | **8.4** or newer |
| Composer | 2.x |
| An S3-compatible object storage with a pre-created, versioned bucket | — |

PHP extensions required at runtime: `curl`, `json`, `mbstring`, `xml`, `openssl`.

---

## Installation

### 1. Drop the app into your owncloud.online server

```bash
cd /var/www/owncloud/apps
git clone https://github.com/GrossLukas/files_primary_s3.git
cd files_primary_s3
```

### 2. Install vendor dependencies

```bash
composer install --no-dev --optimize-autoloader
```

This pulls in `aws/aws-sdk-php` and the bundled Guzzle stack.

### 3. Set file ownership

```bash
chown -R www-data:www-data /var/www/owncloud/apps/files_primary_s3
```

### 4. Enable the app

```bash
sudo -u www-data php /var/www/owncloud/occ app:enable files_primary_s3
```

> **⚠️ Important:** Enable the app **before** you create the first user, or migrate an empty instance. ownCloud does not move existing files from the local filesystem into S3 automatically.

---

## Configuration

Add an `objectstore` entry to `/var/www/owncloud/config/config.php`:

```php
<?php
$CONFIG = [
    // ... existing config ...

    'objectstore' => [
        'class' => 'OCA\\Files_Primary_S3\\S3Storage',
        'arguments' => [
            'bucket' => 'owncloud-primary',
            'options' => [
                'version'     => 'latest',
                'region'      => 'eu-central-1',
                'endpoint'    => 'https://s3.eu-central-1.amazonaws.com',
                'use_path_style_endpoint' => false,
                'credentials' => [
                    'key'    => 'YOUR_ACCESS_KEY_ID',
                    'secret' => 'YOUR_SECRET_ACCESS_KEY',
                ],
            ],
            // optional:
            'serversideencryption' => 'AES256',
            'part_size'            => 524288000,   // 500 MB chunks
            'concurrency'          => 5,
            'availableStorage'     => 1099511627776, // 1 TB quota hint for apps/metrics
        ],
    ],
];
```

### Configuration reference

| Key | Required | Description |
|---|---|---|
| `bucket` | **yes** | Name of an existing, versioned S3 bucket |
| `options.version` | yes | AWS SDK API version, usually `'latest'` |
| `options.region` | yes | S3 region (`'us-east-1'`, `'eu-central-1'`, …) |
| `options.endpoint` | recommended | Full endpoint URL — required for any non-AWS S3 (Ceph, MinIO, Scality, B2) |
| `options.use_path_style_endpoint` | no | `true` for Ceph/MinIO, `false` for AWS-style virtual-hosted buckets |
| `options.credentials.key` / `.secret` | yes | S3 access credentials |
| `serversideencryption` | no | e.g. `'AES256'` |
| `part_size` | no | Part size in bytes for multipart upload (min 5 MiB, default 5 MiB) |
| `concurrency` | no | Number of parallel parts during multipart upload (default 3) |
| `availableStorage` | no | Optional byte count used by ownCloud core as storage capacity hint |

> 🔐 **Encryption note:** ownCloud's app-level "Default Encryption Module" is automatically disabled when this app is active — use S3 server-side encryption instead.

---

## Starting and verifying the setup

After enabling the app and writing the config, no service restart is required for ownCloud — the next request picks up the new storage backend. If you run PHP-FPM with OPcache, reload it once:

```bash
sudo systemctl reload php8.4-fpm
```

### Smoke test

```bash
# List configured buckets and verify the connection works:
sudo -u www-data php /var/www/owncloud/occ s3:list

# Confirm the app is loaded:
sudo -u www-data php /var/www/owncloud/occ app:list | grep files_primary_s3

# Upload a test file via WebDAV / web UI, then list it in the bucket:
sudo -u www-data php /var/www/owncloud/occ s3:list owncloud-primary
```

If the WebDAV upload completes and the object appears in `s3:list`, everything is wired up correctly.

---

## OCC commands

The app ships two OCC subcommands.

### `occ s3:list [bucket] [object]`

Inspect the configured S3 backend.

```bash
# All buckets (with versioning + CORS status):
occ s3:list

# Objects in a bucket:
occ s3:list owncloud-primary

# All versions of one object (and its delete markers):
occ s3:list owncloud-primary urn:oid:42
```

### `occ s3:create-bucket <bucket> [--update-configuration] [--accept-warning]`

Creates a bucket and enables versioning. **Development convenience only** — for production, create the bucket with your provider's tools so you control region, encryption, lifecycle, and CORS.

```bash
occ s3:create-bucket owncloud-primary --accept-warning
```

| Option | Effect |
|---|---|
| `--accept-warning` | Skip the interactive "are you sure" prompt |
| `--update-configuration` | If the bucket already exists, still apply versioning settings |

---

## Daily usage

Once configured, the app is **invisible to end users**. They use ownCloud exactly as before — web UI, desktop client, mobile, WebDAV — and every uploaded file lands in S3 transparently.

What changes for the **administrator**:

- **Backups** — back up the database *and* the S3 bucket (or rely on bucket replication / versioning).
- **Disk usage** — `du` on the data directory shows almost nothing; query S3 for actual storage consumption.
- **File restore** — deleted file? Use S3 versioning. The included `IVersionedObjectStorage` implementation surfaces versions through ownCloud's standard versions UI as well.
- **No app-level encryption** — the "Default Encryption Module" is disabled by design (S3 + ownCloud encryption are incompatible). Use `serversideencryption` instead.

### Admin panel

After enabling, **Settings → Admin → Security → Encryption** shows a banner explaining that ownCloud-side encryption is unavailable while S3 primary storage is active. This is informational only.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `No S3 ObjectStore available` on first request | Wrong endpoint/credentials, or the IAM user lacks `s3:ListAllMyBuckets` |
| `Bucket <…> does not exist.` | Bucket name typo, or the user can't see the bucket (region/permissions) |
| `Upload failed. Please ask your administrator…` in client + `MultipartUploadException` in `owncloud.log` | Network issue or provider hiccup — check the log; for B2, the app already retries once |
| Files upload but the web UI shows them as 0 bytes | Database/storage size mismatch — usually means the upload was interrupted; re-upload |
| `Cannot modify readonly property` after upgrading | Stale OPcache — `systemctl reload php8.4-fpm` |

The full SDK error is always logged to `owncloud.log` via `OC::$server->getLogger()->logException()`.

---

## Development

```bash
# Install all dev dependencies (incl. phpstan, phan, php-cs-fixer):
composer install
composer bin all install

# Style:
make test-php-style          # check
make test-php-style-fix      # fix

# Static analysis:
make test-php-phpstan        # PHPStan level 5
make test-php-phan           # Phan
```

CI (GitHub Actions) runs code style + commit-message linting on every push; see `.github/workflows/`.

---

## Attribution & License

Originally developed by **ownCloud GmbH**, licensed under **GPL-2.0**.
Modifications for **owncloud.online** and **PHP 8.4** by **BW-Tech GmbH**.

Original project: https://github.com/owncloud/files_primary_s3
Distribution this fork targets: https://github.com/BWTECH-github/owncloud.online
Maintainer: https://bw.tech

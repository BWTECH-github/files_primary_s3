# files_primary_s3

S3-compatible object storage as primary storage for [owncloud.online](https://github.com/BWTECH-github/owncloud.online).

This is a fork of the original [owncloud/files_primary_s3](https://github.com/owncloud/files_primary_s3) app, updated for **PHP 8.4** and maintained as part of the owncloud.online distribution by [BW.Tech](https://bw.tech).

## About

For its benefits over traditional file system storage, object storage has become more and more popular. Speaking simply, object storages split files into parts of the same size and store them — including the metadata to assemble these objects to files. In contrast to file system storage this enables infinite scalability to cope for an exponentially growing amount of data. Furthermore object storage systems like CEPH or Scality RING provide built-in features for automatic data replication, redundancy/high availability and even geo-distribution which are necessities for professional production environments.

This extension enables the server to communicate with the widely spread S3 protocol (S3 HTTP API) to use object storage as its primary storage location.

## Supported features

- S3 multi-part upload (enables uploading files > 5 GB)
- S3 versioning

## Requirements

- owncloud.online (server version 11)
- PHP 8.4

## Attribution

Originally developed by ownCloud GmbH (GPL-2.0). Modifications for owncloud.online and PHP 8.4 by BW-Tech GmbH.

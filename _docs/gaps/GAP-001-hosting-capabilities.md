---
gap_id: GAP-001
title: Hosting capabilities
description: Verify production versions, extensions, cron, HTTPS egress, document-root controls and deployment access.
keywords: [hosting, php, mariadb, cron, ftp]
created: 2026-08-26
owner: user
---

# GAP-001: Hosting Capabilities

## Question

Which PHP and MariaDB versions, PHP extensions, cron modes, document-root settings, outbound HTTPS rules and file permissions does the webhoster provide?

## Context

- Evidence: [Technology Stack](../architecture/07-tech-stack.md)
- Keywords: hosting, PHP, MariaDB, cron, FTP
- Required PHP extensions: cURL, JSON, mbstring, OpenSSL, PDO, PDO MySQL.
- Preferred setup: configurable `public/` document root, non-public secret file, PHP CLI cron and TLS certificate.

## Impact

The answers determine compatible syntax, database DDL, token encryption, secure file placement, scheduler entry point and whether the proposed deployment is feasible.

## Decision Needed

- Hoster/product name or its technical specification link.
- PHP and MariaDB versions and enabled extensions.
- CLI cron availability and command format; otherwise HTTP scheduler capabilities.
- Ability to set the domain document root to `public/` or enforce `.htaccess` denies.
- Confirmation of outbound HTTPS to `il.srf.ch`, `accounts.spotify.com` and `api.spotify.com`.
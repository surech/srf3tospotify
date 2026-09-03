# Technology Stack

## Production

| Technology | Selection | Rationale |
| --- | --- | --- |
| Language | PHP 8.2+ | Broad shared-hosting availability, typed properties, enums and modern date APIs |
| Web runtime | Apache or hoster PHP-FPM | Supplied by shared hoster |
| Application style | Framework-free modular monolith | Small scope and FTP deployment do not justify framework lifecycle or cache tooling |
| Dependency management | Composer 2 with PSR-4 | Familiar packaging, test tooling and deterministic locked dependencies |
| HTTP | PHP cURL extension behind a small transport interface | No runtime package required; controllable timeouts and headers |
| Image processing | PHP GD extension | Converts bundled PNG covers to Spotify-compatible JPEG payloads |
| Database | MariaDB with InnoDB via PDO | Hosting constraint, transactions, unique constraints and advisory locks |
| UI | Server-rendered semantic HTML and project CSS | Single-owner workflow, minimal JavaScript and deployment footprint |
| Logging | JSON Lines to protected file and CLI streams | Searchable without external monitoring service |

## Development and Test

| Technology | Selection | Rationale |
| --- | --- | --- |
| Containers | Docker Compose | Reproducible local PHP, Apache and MariaDB without host installation |
| Unit tests | PHPUnit | Standard PHP test runner and mature assertions |
| Static analysis | PHPStan | Compensates for PHP runtime typing gaps and helps a Java-oriented maintainer |
| Formatting | PHP-CS-Fixer with PSR-12 baseline | Deterministic style and low review noise |
| Database tests | Dedicated MariaDB container | Tests real SQL, collation, constraints and advisory locking |

## Release Artifact

- Composer install runs locally in Docker with `--no-dev --classmap-authoritative`.
- Release contains application source, migrations, templates, public assets, non-public playlist covers and production `vendor/`.
- `.env`, test output, local logs and Docker database data are excluded.
- The public document root points to `public/`; where impossible, server rules deny access to configuration, source, `vendor/`, database and `var/` paths.
- Build produces an FTP-ready directory, compressed archive and SHA-256 checksum.
- Hosts without SSH apply migrations through the Bearer-protected maintenance endpoint; credentials never appear in URLs.

## Compatibility Gate

Implementation remains blocked on [GAP-001](../gaps/GAP-001-hosting-capabilities.md) for final minimum versions and extensions. The local image must match the verified production PHP and MariaDB major/minor versions before deployment.
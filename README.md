# SRF3ToSpotify

PHP-/MariaDB-Anwendung für SRF-3-Ausstrahlungen, Song-Rankings und daraus synchronisierte Spotify-Playlists.

## Lokal starten

Voraussetzung: Docker mit Compose.

```bash
docker compose up -d --build --wait
docker compose exec -T --user www-data app php bin/console migrate
```

Danach: [http://localhost:8080](http://localhost:8080)

Die lokale `.env` bleibt durch `.gitignore` ausgeschlossen. Passwort-Hash erzeugen:

```bash
docker compose run --rm app php -r 'echo password_hash("mein-passwort", PASSWORD_DEFAULT), PHP_EOL;'
```

Hash als einfach quotierten Wert `ADMIN_PASSWORD_HASH='...'` in `.env` eintragen. Die Anwendung entfernt die äusseren Anführungszeichen beim Laden; sie gehören nicht zum Hash. Container danach neu erstellen:

```bash
docker compose up -d --force-recreate --wait
```

## Befehle

```bash
docker compose exec -T --user www-data app php bin/console diagnostics
docker compose exec -T --user www-data app php bin/console import --from=2026-08-24 --to=2026-08-24
docker compose exec -T --user www-data app php bin/console import --from=2026-09-17 --to=2026-09-17
docker compose exec -T --user www-data app php bin/console ranking --days=30 --limit=50
docker compose exec -T --user www-data app php bin/console sync
docker compose exec -T --user www-data app php bin/console cleanup --days=90
```

`import-yesterday`, `sync` und `cleanup` eignen sich für Hoster-Cronjobs. Manuelle Dashboard-Aktionen verwenden dieselben Import- und Sync-Services.

## Qualität

```bash
docker compose exec -T app composer quality
docker compose exec -T app vendor/bin/phpunit --coverage-text --coverage-filter src
```

`composer quality` prüft PHP-Formatierung, PHPStan, die JavaScript-Tests mit Node.js und die vollständige PHPUnit-Suite.

## Spotify

1. Spotify-App im Developer Dashboard erstellen.
2. Exakte Callback-URL registrieren: `https://DEINE-DOMAIN/spotify/callback`.
3. `SPOTIFY_CLIENT_ID` und `SPOTIFY_CLIENT_SECRET` in `.env` setzen.
4. Dashboard öffnen und **Spotify verbinden** wählen.

Development Mode genügt für persönlichen Betrieb. Spotify verlangt aktuell ein Premium-Konto des App-Eigentümers.
Nach einem Update von einer Version ohne Playlist-Cover **Spotify verbinden** erneut wählen, damit Spotify den zusätzlichen Bild-Upload-Scope freigibt.

Der erste Sync erstellt drei öffentliche Playlists. Bereits vorhandene Playlists werden beim nächsten Sync öffentlich geschaltet:

- **SRF 3 - Top 50**: meistgespielte Songs der letzten 30 vollständigen Tage.
- **SRF 3 - Der Morgen**: 50 meistgespielte Songs der letzten 30 vollständigen Tage, eingeschränkt auf Montag bis Freitag von 06:00 Uhr inklusive bis 10:00 Uhr exklusive in Schweizer Lokalzeit.
- **SRF 3 - Schweizer Musiktag 2026**: alle eindeutigen Songs vom 17. September 2026, 05:00 Uhr inklusive bis 24:00 Uhr exklusive in Schweizer Lokalzeit.

Vor dem ersten Sync der Musiktag-Playlist muss der 17. September 2026 einmal importiert werden. Bei jedem Sync lädt die Anwendung die drei PNG-Dateien aus `resources/playlist-covers/` als Cover hoch, einschliesslich `schweizer-musiktag.png`.

## Deployment

```bash
docker compose exec -T app composer release
```

Upload-Verzeichnis: `build/release/`; Archiv: `build/srf3tospotify-release.tar.gz`. Produktionsschritte: [DEPLOYMENT.md](DEPLOYMENT.md).
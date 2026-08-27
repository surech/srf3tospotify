# SRF3ToSpotify

PHP-/MariaDB-Anwendung für SRF-3-Ausstrahlungen, Song-Rankings und eine daraus synchronisierte Spotify-Playlist.

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

Hash als einfach quotierten Wert `ADMIN_PASSWORD_HASH='...'` in `.env` eintragen; Container danach neu erstellen:

```bash
docker compose up -d --force-recreate --wait
```

## Befehle

```bash
docker compose exec -T --user www-data app php bin/console diagnostics
docker compose exec -T --user www-data app php bin/console import --from=2026-08-24 --to=2026-08-24
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

## Spotify

1. Spotify-App im Developer Dashboard erstellen.
2. Exakte Callback-URL registrieren: `https://DEINE-DOMAIN/spotify/callback`.
3. `SPOTIFY_CLIENT_ID` und `SPOTIFY_CLIENT_SECRET` in `.env` setzen.
4. Dashboard öffnen und **Spotify verbinden** wählen.

Development Mode genügt für persönlichen Betrieb. Spotify verlangt aktuell ein Premium-Konto des App-Eigentümers.

## Deployment

```bash
docker compose exec -T app composer release
```

Upload-Verzeichnis: `build/release/`; Archiv: `build/srf3tospotify-release.tar.gz`. Produktionsschritte: [DEPLOYMENT.md](DEPLOYMENT.md).
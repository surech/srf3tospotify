# Deployment auf Shared Hosting

## 1. Hoster prüfen

- PHP 8.2+ mit `curl`, `intl`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`
- MariaDB 10.6+
- HTTPS-Zertifikat
- Ausgehendes HTTPS zu `il.srf.ch`, `accounts.spotify.com`, `api.spotify.com`
- Einstellbare Dokumentwurzel auf Verzeichnis `public/`
- CLI-Cron bevorzugt; externer HTTPS-Scheduler als Fallback

Ohne separate `public/`-Dokumentwurzel nicht deployen: Root-`.htaccess` blockiert absichtlich Quellcode und Secrets.

## 2. Release bauen

```bash
docker compose exec -T app composer quality
docker compose exec -T app composer release
sha256sum -c build/srf3tospotify-release.tar.gz.sha256
```

- FTP-Quelle: Inhalt aus `build/release/`
- Alternative: Archiv im Hoster-Dateimanager hochladen und ausserhalb der Dokumentwurzel entpacken
- Enthalten: Produktionscode, Migrationen, Templates, Public Assets, Composer-Vendor
- Ausgeschlossen: `.env`, Tests, Dockerdaten, Logs, Dev-Abhängigkeiten

## 3. Verzeichnisse

Beispiel:

```text
/home/account/apps/srf3tospotify/       Release-Inhalt
/home/account/apps/srf3tospotify/public Dokumentwurzel der Domain
```

Schreibrechte nur für `var/log/` und `var/tmp/`; übrige Dateien read-only für Webprozess.

## 4. Secrets konfigurieren

`.env.example` nach `.env` kopieren, Werte ersetzen, Datei nie öffentlich ablegen oder versionieren.

```bash
docker compose run --rm app php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
docker compose run --rm app php -r 'echo password_hash("PRODUKTIONSPASSWORT", PASSWORD_DEFAULT), PHP_EOL;'
```

Pflichtwerte:

- `APP_ENV=production`
- `APP_KEY`: mindestens 32 zufällige Zeichen; nach OAuth-Inbetriebnahme sichern und nicht ändern
- `APP_URL`: exakte HTTPS-Basis-URL ohne abschliessenden Slash
- `DB_*`: MariaDB-Zugang mit Rechten für Tabellen und Migrationen
- `ADMIN_PASSWORD_HASH`: Ergebnis von `password_hash`
- `CRON_TOKEN`: mindestens 32 zufällige Zeichen
- `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`

CLI und Webserver müssen auf Shared Hosting unter demselben Account laufen oder gemeinsame Schreibrechte auf `var/log/` und `var/tmp/` besitzen. Lokale Docker-CLI-Beispiele verwenden deshalb `--user www-data`.

Dollarzeichen in Passwort-Hashes bei Docker Compose einfach quotieren: `ADMIN_PASSWORD_HASH='$2y$...'`.

## 5. Datenbank migrieren

Mit Hoster-CLI:

```bash
php bin/console migrate
php bin/console diagnostics
```

Nur FTP, ohne SSH/CLI:

```bash
curl --fail-with-body \
  --request POST \
  --header "Authorization: Bearer $CRON_TOKEN" \
  https://DEINE-DOMAIN/internal/maintenance/migrate
```

Erwartung: JSON mit `applied` und `skipped`. Token niemals als Query-Parameter verwenden.

## 6. Smoke-Test

```bash
curl --fail https://DEINE-DOMAIN/health
```

Danach Dashboard anmelden, einen vergangenen Tag importieren, denselben Tag erneut importieren und prüfen: zweiter Lauf `0` neue Datensätze.

## 7. Spotify verbinden

- Callback im Spotify Dashboard: `https://DEINE-DOMAIN/spotify/callback`
- Dashboard-Aktion **Spotify verbinden**
- Zielplaylist wird beim ersten Sync erstellt
- Private Playlist und Top 50 der letzten 30 vollständigen Tage bleiben aktuelle Standardannahme

## 8. Automatisierung

CLI-Cron, Europe/Zurich-bezogene Datumslogik in Anwendung:

```cron
15 2 * * * cd /home/account/apps/srf3tospotify && php bin/console import-yesterday >> var/log/cron.log 2>&1
45 2 * * * cd /home/account/apps/srf3tospotify && php bin/console sync --trigger=cron >> var/log/cron.log 2>&1
15 3 * * 0 cd /home/account/apps/srf3tospotify && php bin/console cleanup --days=90 >> var/log/cron.log 2>&1
```

HTTP-Fallback:

```bash
curl --fail --request POST --header "Authorization: Bearer $CRON_TOKEN" https://DEINE-DOMAIN/internal/cron/import
curl --fail --request POST --header "Authorization: Bearer $CRON_TOKEN" https://DEINE-DOMAIN/internal/cron/sync
```

Scheduler muss Bearer-Header unterstützen. Andernfalls CLI-Cron verwenden; Token in URL bleibt verboten.

## 9. Update und Rollback

1. Datenbank- und Dateibackup erstellen.
2. Neues Release in separates Verzeichnis hochladen.
3. Bestehende Produktions-`.env` übernehmen.
4. Migration ausführen.
5. Dokumentwurzel atomar auf neues `public/` umschalten.
6. Healthcheck, Login, Import und Ranking prüfen.

Rollback: Dokumentwurzel auf vorheriges Release zurückschalten. Datenbankmigrationen bleiben vorwärtskompatibel; kein automatisches Down-Migration-Skript. Vor destruktiven künftigen Migrationen bleibt Datenbankbackup Pflicht.
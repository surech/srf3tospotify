---
id: SRF3SPOTIFY-001.subtask-1
title: "Lokale Laufzeit und Persistenz"
story: SRF3SPOTIFY-001
status: approved
suggested_model: gpt-5.4-mini
suggested_model_reason: tier-medium;context-128000;capabilities-testing
---

# SRF3SPOTIFY-001.subtask-1: Lokale Laufzeit und Persistenz

> Als Entwickler will ich reproduzierbare PHP- und MariaDB-Laufzeit, damit lokale Entwicklung ohne installierten Webserver funktioniert.

## Acceptance Criteria

- [x] Wenn `docker compose up` startet, dann laufen PHP-Webserver und MariaDB mit Healthchecks.
- [x] Wenn Migrationen gegen leere Datenbank laufen, dann entstehen alle Tabellen, Indizes und Constraints aus Architektur-Datenmodell.
- [x] Wenn Migrationen erneut laufen, dann bleiben Schema und Daten unverändert.
- [x] Wenn Konfiguration fehlt, dann beendet Anwendung Start mit konkreter, geheimnisfreier Fehlermeldung.
- [x] Wenn Release gebaut wird, dann enthält Artefakt Produktions-Abhängigkeiten, aber keine Secrets, Tests oder Laufzeitdaten.

## Scope

- **In:** Docker Compose, Composer, Konfiguration, PDO, Migrationen, Logging, Release-Grundlage
- **Out:** SRF-Import, Spotify, Dashboard-Funktionen

## Assumptions & Risks

- Assumption: PHP 8.2+ und MariaDB 10.6+ auf Zielhosting
- Risk: Produktionsversion abweichend; GAP-001 vor Upload klären

## Dependencies

- Upstream: Architektur-DRAFT
- Downstream: subtask-2, subtask-3, subtask-4

## Definition of Done

- [x] Code reviewed + tests pass
- [x] Coverage >= 80%
- [x] Deployable alone
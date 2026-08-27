---
id: SRF3SPOTIFY-001.subtask-4
title: "Geschuetzter Betrieb und Deployment"
story: SRF3SPOTIFY-001
status: approved
suggested_model: gpt-5.4-mini
suggested_model_reason: tier-medium;context-128000;capabilities-testing
---

# SRF3SPOTIFY-001.subtask-4: Geschuetzter Betrieb und Deployment

> Als Besitzer will ich geschützte manuelle und automatische Abläufe, damit Betrieb auf Shared Hosting sicher und nachvollziehbar bleibt.

## Acceptance Criteria

- [x] Wenn Besitzer angemeldet ist, dann zeigt Dashboard Status, Ranking, offene Matches und letzte Läufe.
- [x] Wenn Besitzer Import oder Sync auslöst, dann schützt CSRF-Token Änderung und zeigt korrelierbares Resultat.
- [x] Wenn anonymer Aufruf Verwaltungsdaten anfordert, dann erhält Client keine Daten.
- [x] Wenn HTTP-Cron-Fallback genutzt wird, dann akzeptiert Anwendung nur Bearer-Token im Header.
- [x] Wenn CLI-Cron verfügbar ist, dann führen `import-yesterday`, `sync` und `cleanup` gleiche Services wie Dashboard aus.
- [x] Wenn zwei gleiche Jobs parallel starten, dann verhindert MariaDB-Advisory-Lock zweite Ausführung.
- [x] Wenn Logs geschrieben werden, dann enthalten sie Korrelation, Status, Anzahl und Dauer, aber keine Secrets oder Tokens.
- [x] Wenn FTP-Release gebaut wird, dann dokumentiert Paket Upload, Migration, Secret-Konfiguration, Cron und Rollback.

## Scope

- **In:** Session-Login, CSRF, Dashboard, Cron-Routen, CLI-Automation, Logs, Release und Betriebsdokumentation
- **Out:** Öffentliche Registrierung, Rollenmodell, CI/CD zum unbekannten Hoster

## Assumptions & Risks

- Assumption: Ein Besitzer, HTTPS-Pflicht
- Risk: Hoster-Cron und Dokumentwurzel unbekannt; beide Trigger-Varianten, GAP-001

## Dependencies

- Upstream: subtask-1, subtask-2, subtask-3
- Downstream: keine

## Definition of Done

- [x] Code reviewed + tests pass
- [x] Coverage >= 80%
- [x] Deployable alone
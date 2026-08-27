---
id: SRF3SPOTIFY-001.subtask-2
title: "SRF-Import und Auswertung"
story: SRF3SPOTIFY-001
status: approved
suggested_model: gpt-5.4-mini
suggested_model_reason: tier-medium;context-128000;capabilities-testing
---

# SRF3SPOTIFY-001.subtask-2: SRF-Import und Auswertung

> Als Besitzer will ich SRF-Ausstrahlungen importieren und rangieren, damit meistgespielte Songs sichtbar werden.

## Acceptance Criteria

- [x] Wenn gültiger lokaler Datumsbereich importiert wird, dann speichert Anwendung alle SRF-Ausstrahlungen mit UTC-Zeit und Original-Offset.
- [x] Wenn gleicher Bereich erneut importiert wird, dann entstehen keine zusätzlichen Ausstrahlungen.
- [x] Wenn gleicher Song zu verschiedenen Zeiten läuft, dann entstehen getrennte Ausstrahlungen.
- [x] Wenn SRF exakt `pageSize=500` Datensätze liefert, dann teilt Import Zeitfenster und dedupliziert Intervallgrenzen.
- [x] Wenn SRF-Schema ungültig oder Netzwerk nicht verfügbar ist, dann bleibt Datenimport atomar und Lauf endet fehlgeschlagen.
- [x] Wenn Ranking abgefragt wird, dann liefert Anwendung eindeutige Songs nach Spielanzahl, letztem Spiel und stabilem Text-Tie-Breaker.
- [x] Edge: Wenn Sommerzeit wechselt, dann deckt Tagesimport vollständigen Europe/Zurich-Kalendertag ohne Lücke ab.

## Scope

- **In:** SRF-Client, Normalisierung, Ereignisschlüssel, Import-Service, Ranking, CLI, Tests
- **Out:** Spotify-Matching, Browser-Dashboard

## Assumptions & Risks

- Assumption: SRF-Endpunkt bleibt ohne Authentisierung erreichbar
- Risk: Unpublizierter Vertrag; strikte Validierung und gespeicherter Fehlerstatus

## Dependencies

- Upstream: subtask-1
- Downstream: subtask-3, subtask-4

## Definition of Done

- [x] Code reviewed + tests pass
- [x] Coverage >= 80%
- [x] Deployable alone
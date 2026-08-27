---
id: SRF3SPOTIFY-001.subtask-3
title: "Spotify-Matching und Playlist-Synchronisierung"
story: SRF3SPOTIFY-001
status: approved
suggested_model: gpt-5.4-mini
suggested_model_reason: tier-medium;context-128000;capabilities-testing
---

# SRF3SPOTIFY-001.subtask-3: Spotify-Matching und Playlist-Synchronisierung

> Als Besitzer will ich Ranking-Songs Spotify zuordnen und Playlist abgleichen, damit Spotify automatisch aktuelle Radio-Favoriten enthält.

## Acceptance Criteria

- [x] Wenn Besitzer Spotify autorisiert, dann validiert Anwendung OAuth-State und speichert Tokens verschlüsselt.
- [x] Wenn Access Token abläuft, dann erneuert Anwendung Token über Refresh Token ohne erneute Benutzerfreigabe.
- [x] Wenn Ranking ungelösten Song enthält, dann sucht Anwendung bis zu 10 Kandidaten und speichert Score sowie Status.
- [x] Wenn bester Score mindestens 0.90 und Vorsprung mindestens 0.10 beträgt, dann akzeptiert Anwendung Match automatisch; sonst Status `review`.
- [x] Wenn Besitzer Track-ID auswählt oder Song ablehnt, dann übersteuert Entscheidung spätere automatische Matches.
- [x] Wenn Playlist synchronisiert wird, dann entspricht Reihenfolge eindeutigem gewünschten Ranking und Schreibbatches enthalten höchstens 100 URIs.
- [x] Error: Wenn Spotify `429` liefert, dann respektiert Anwendung `Retry-After` und erhält wiederholbaren Laufstatus.

## Scope

- **In:** OAuth Code Flow, Token-Verschlüsselung, Spotify-Client, Matching, Overrides, Playlist-Reconciliation
- **Out:** Mehrbenutzerbetrieb, Extended Quota Mode, Audio-Wiedergabe

## Assumptions & Risks

- Assumption: Persönliches Premium-Konto und Development-Mode-App vorhanden
- Risk: Credentials und Callback fehlen; Live-Abnahme bis GAP-003 blockiert
- Assumption: Playlist-Standard aus A-003 bis GAP-002-Auflösung

## Dependencies

- Upstream: subtask-1, subtask-2
- Downstream: subtask-4

## Definition of Done

- [x] Code reviewed + tests pass
- [x] Coverage >= 80%
- [x] Deployable alone
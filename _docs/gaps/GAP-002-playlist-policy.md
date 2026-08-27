---
gap_id: GAP-002
title: Playlist policy
description: Decide ranking period, playlist size, visibility, channel scope and treatment of unresolved Spotify matches.
keywords: [playlist, ranking, window, visibility, matching]
created: 2026-08-26
owner: user
---

# GAP-002: Playlist Policy

## Question

Which statistical window and ranking rules should define the generated Spotify playlist?

## Context

- Evidence: [Business Logic](../architecture/05-business-logic.md)
- Keywords: playlist, ranking, top songs, visibility
- Current assumption A-003: top 50 unique songs from the last 30 complete Europe/Zurich days, private playlist.
- Proposed tie-breaker: play count, latest play, artist and title.

## Impact

The decision controls SQL queries, configuration defaults, playlist synchronization, dashboard wording and acceptance tests.

## Decision Needed

- Ranking window and maximum number of tracks.
- One fixed channel or selectable/multiple channels.
- Private or public Spotify playlist.
- Whether unresolved tracks are omitted, block the sync, or require manual review first.
- Whether manual exclusions and artist/song blacklists are required.
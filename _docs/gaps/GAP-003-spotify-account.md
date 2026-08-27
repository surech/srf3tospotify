---
gap_id: GAP-003
title: Spotify account and app
description: Confirm Premium eligibility, app registration, callback URL and whether to create or reuse the target playlist.
keywords: [spotify, oauth, premium, callback, playlist]
created: 2026-08-26
owner: user
---

# GAP-003: Spotify Account and App

## Question

Is a Spotify Premium owner account and Development Mode app available, and should the application create a new playlist or manage an existing owned playlist?

## Context

- Evidence: [References](../architecture/11-references.md)
- Keywords: Spotify, OAuth, Premium, callback, playlist ownership
- The exact HTTPS callback must be known before Spotify authorization can succeed.
- Client ID, client secret, refresh token and encryption key must not be shared in chat or committed.

## Impact

Without an eligible account, registered redirect URI and owned target playlist, live Spotify acceptance testing and automatic synchronization cannot complete.

## Decision Needed

- Confirmation of Premium account and Spotify developer app.
- Production HTTPS base URL and exact callback path.
- New or existing playlist, desired name and visibility.
- Confirmation that only the owner's Spotify account will use the application.
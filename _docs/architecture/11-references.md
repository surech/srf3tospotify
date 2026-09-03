# References

## Verified Inputs

- User requirements supplied in chat on 2026-08-25.
- SRF sample request supplied by the user and fetched successfully on 2026-08-26.
- Observed SRF root: `songList`; observed element fields: `isPlayingNow`, `date`, `duration`, `title`, `artist.name`; 330 events for 2026-08-24.
- Local tools verified on 2026-08-26: Docker 29.6.0 and Docker Compose 5.3.1; no host PHP or Composer.

## External Documentation

- [Spotify Authorization Code Flow](https://developer.spotify.com/documentation/web-api/tutorials/code-flow)
- [Spotify Search for Item](https://developer.spotify.com/documentation/web-api/reference/search)
- [Spotify Create Playlist](https://developer.spotify.com/documentation/web-api/reference/create-playlist)
- [Spotify Add Custom Playlist Cover Image](https://developer.spotify.com/documentation/web-api/reference/upload-custom-playlist-cover)
- [Spotify Add Items to Playlist](https://developer.spotify.com/documentation/web-api/reference/add-items-to-playlist)
- [Spotify Quota Modes](https://developer.spotify.com/documentation/web-api/concepts/quota-modes)
- [PHP supported versions](https://www.php.net/supported-versions.php)
- [MariaDB advisory locks](https://mariadb.com/kb/en/get_lock/)

## Current Spotify Constraints

- Authorization Code Flow supports long-running server applications and refresh tokens.
- A newly created Development Mode app is suitable for one personal account, requires the owner to have Spotify Premium, and supports up to five allowlisted users.
- Search returns at most 10 results per requested item type according to the fetched 2026 reference.
- Custom playlist covers require `ugc-image-upload` and a Base64-encoded JPEG payload no larger than 256 KB.
- Playlist item writes accept at most 100 URIs per request.
- New code must use playlist `items` contracts; deprecated `tracks` update contracts are excluded.

## Assumption Policy

Anything not listed as a verified input or external contract is a proposal or an assumption. Assumptions affecting deployment or product behavior are tracked in Quick Reference and the gap files.

## Operational Documents

- [Local development and commands](../../README.md)
- [Shared-hosting deployment](../../DEPLOYMENT.md)
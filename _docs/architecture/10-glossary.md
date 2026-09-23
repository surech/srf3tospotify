# Glossary

| Term | Definition |
| --- | --- |
| Broadcast event / play | One song occurrence on a radio channel at an exact time |
| Logical song | Normalized artist/title identity used to group multiple plays |
| Event hash | Deterministic SHA-256 key identifying one SRF broadcast event |
| Identity hash | Deterministic SHA-256 key identifying one logical song |
| Ranking window | Complete Europe/Zurich calendar days included in statistics |
| Desired snapshot | Ordered, persisted list of Spotify tracks a sync intends to publish |
| Playlist target | Current ordered preview built from ranking, active exclusions, accepted matches and Spotify-track deduplication |
| Ignore rule period | Immutable interval during which one logical song is excluded globally or from one playlist |
| Reactivation | Closing an active ignore-rule period; no forced playlist insertion occurs |
| Manual override | Owner-selected Spotify track or trackless manual review state that supersedes automatic matching |
| SRF Integration Layer | Public JSON endpoint supplying SRF radio song lists |
| Development Mode | Spotify app mode for development and small allowlisted audiences |
| Authorization Code Flow | Spotify OAuth flow providing renewable owner authorization |
| Advisory lock | MariaDB connection-scoped lock preventing concurrent operations |
| Correlation ID | Identifier joining one trigger, logs and persisted run state |
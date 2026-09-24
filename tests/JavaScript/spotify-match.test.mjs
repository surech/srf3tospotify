import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildSpotifySearchUrl,
  requestSpotifySearch,
  spotifyErrorMessage,
  spotifyMetadata,
} from '../../public/assets/spotify-match.mjs';

function response(status, payload, headers = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: {
      get(name) {
        return headers[name.toLowerCase()] ?? null;
      },
    },
    async json() {
      return payload;
    },
  };
}

test('builds a trimmed paged search URL and requires one field', () => {
  const url = buildSpotifySearchUrl('https://app.example', ' Artist ', ' Song ', 20);

  assert.equal(url.pathname, '/admin/spotify/tracks/search');
  assert.equal(url.searchParams.get('artist'), 'Artist');
  assert.equal(url.searchParams.get('title'), 'Song');
  assert.equal(url.searchParams.get('offset'), '20');
  assert.throws(
    () => buildSpotifySearchUrl('https://app.example', ' ', '', 0),
    /Künstler oder Songtitel/,
  );
});

test('requests one Spotify result page and normalizes pagination', async () => {
  let requestedUrl = null;
  let requestedOptions = null;
  const result = await requestSpotifySearch({
    origin: 'https://app.example',
    artist: 'Artist',
    title: '',
    offset: 10,
    fetcher: async (url, options) => {
      requestedUrl = url;
      requestedOptions = options;
      return response(200, {
        items: [{ id: 'track000001', title: 'Song' }],
        offset: 10,
        limit: 10,
        has_more: true,
      });
    },
  });

  assert.equal(requestedUrl.searchParams.get('artist'), 'Artist');
  assert.equal(requestedOptions.headers.Accept, 'application/json');
  assert.deepEqual(result, {
    items: [{ id: 'track000001', title: 'Song' }],
    offset: 10,
    limit: 10,
    hasMore: true,
  });
});

test('surfaces Spotify rate limits with retry timing', async () => {
  const limited = response(429, { detail: 'limited' }, { 'retry-after': '17' });

  assert.equal(
    await spotifyErrorMessage(limited),
    'Spotify begrenzt die Anfragen. Erneut versuchen in 17 Sekunden.',
  );
  await assert.rejects(
    requestSpotifySearch({
      origin: 'https://app.example',
      artist: 'Artist',
      title: 'Song',
      offset: 0,
      fetcher: async () => response(409, { detail: 'authorization required' }),
    }),
    /Spotify ist nicht verbunden/,
  );
});

test('formats album, year and duration without scores', () => {
  assert.equal(spotifyMetadata({
    album: 'Album',
    release_year: '2024',
    duration_ms: 181_000,
  }), 'Album · 2024 · 3:01 Min.');
  assert.equal(spotifyMetadata({ duration_ms: 0 }), '0:00 Min.');
});
export function buildSpotifySearchUrl(origin, artist, title, offset) {
  const normalizedArtist = artist.trim();
  const normalizedTitle = title.trim();
  if (normalizedArtist === '' && normalizedTitle === '') {
    throw new Error('Künstler oder Songtitel ist erforderlich.');
  }
  if (!Number.isInteger(offset) || offset < 0) {
    throw new Error('Ungültige Seitennummer der Spotify-Suche.');
  }

  const url = new URL('/spotify/tracks/search', origin);
  url.searchParams.set('artist', normalizedArtist);
  url.searchParams.set('title', normalizedTitle);
  url.searchParams.set('offset', String(offset));

  return url;
}

export async function requestSpotifySearch({
  origin,
  artist,
  title,
  offset,
  signal,
  fetcher = globalThis.fetch,
}) {
  const url = buildSpotifySearchUrl(origin, artist, title, offset);
  const response = await fetcher(url, {
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) {
    throw new Error(await spotifyErrorMessage(response));
  }

  const payload = await response.json();

  return {
    items: Array.isArray(payload.items) ? payload.items : [],
    offset: Number.isInteger(payload.offset) ? payload.offset : offset,
    limit: Number.isInteger(payload.limit) ? payload.limit : 10,
    hasMore: payload.has_more === true,
  };
}

export async function spotifyErrorMessage(response) {
  let detail = '';
  try {
    const payload = await response.json();
    detail = typeof payload.detail === 'string' ? payload.detail : '';
  } catch {
    detail = '';
  }

  if (response.status === 429) {
    const retryAfter = response.headers.get('Retry-After');
    return retryAfter === null
      ? 'Spotify begrenzt die Anfragen. Bitte später erneut versuchen.'
      : `Spotify begrenzt die Anfragen. Erneut versuchen in ${retryAfter} Sekunden.`;
  }
  if (response.status === 401) {
    return 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.';
  }
  if (response.status === 409) {
    return 'Spotify ist nicht verbunden. Bitte Konto erneut verbinden.';
  }

  return detail !== '' ? detail : 'Spotify-Suche fehlgeschlagen.';
}

export function spotifyMetadata(item) {
  const values = [];
  if (typeof item.album === 'string' && item.album !== '') {
    values.push(item.album);
  }
  if (typeof item.release_year === 'string' && item.release_year !== '') {
    values.push(item.release_year);
  }
  if (Number.isInteger(item.duration_ms) && item.duration_ms >= 0) {
    const seconds = Math.round(item.duration_ms / 1000);
    values.push(`${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} Min.`);
  }

  return values.join(' · ');
}
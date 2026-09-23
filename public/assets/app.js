const spotifySearchHelpers = import('./spotify-match.mjs');

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) {
    return;
  }

  const trigger = event.target.closest('[data-dialog-target]');
  if (!(trigger instanceof HTMLButtonElement)) {
    return;
  }

  const dialogId = trigger.dataset.dialogTarget;
  const dialog = dialogId === undefined ? null : document.getElementById(dialogId);
  if (dialog instanceof HTMLDialogElement) {
    trigger.closest('details')?.removeAttribute('open');
    dialog.showModal();
    if (dialog.matches('[data-spotify-match-dialog]')) {
      openSpotifyMatchDialog(dialog);
    }
  }
});

for (const dialog of document.querySelectorAll('.app-dialog')) {
  if (!(dialog instanceof HTMLDialogElement)) {
    continue;
  }

  dialog.addEventListener('click', (event) => {
    const bounds = dialog.getBoundingClientRect();
    const isOutside = event.clientX < bounds.left
      || event.clientX > bounds.right
      || event.clientY < bounds.top
      || event.clientY > bounds.bottom;

    if (event.target === dialog && isOutside) {
      dialog.close();
    }
  });
}

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) {
    return;
  }

  const closeButton = event.target.closest('[data-dialog-close]');
  const dialog = closeButton?.closest('dialog');
  if (dialog instanceof HTMLDialogElement) {
    dialog.close();
  }
});

const spotifyDialogStates = new WeakMap();

function spotifyDialogState(dialog) {
  const existing = spotifyDialogStates.get(dialog);
  if (existing !== undefined) {
    return existing;
  }

  const searchForm = dialog.querySelector('[data-spotify-search-form]');
  const results = dialog.querySelector('[data-spotify-results]');
  const message = dialog.querySelector('[data-spotify-search-message]');
  const searchButton = dialog.querySelector('[data-spotify-search-submit]');
  const loadMoreButton = dialog.querySelector('[data-spotify-load-more]');
  const selectedTrack = dialog.querySelector('[data-selected-track]');
  const assignButton = dialog.querySelector('[data-assign-track]');
  if (!(searchForm instanceof HTMLFormElement)
    || !(results instanceof HTMLElement)
    || !(message instanceof HTMLElement)
    || !(searchButton instanceof HTMLButtonElement)
    || !(loadMoreButton instanceof HTMLButtonElement)
    || !(selectedTrack instanceof HTMLInputElement)
    || !(assignButton instanceof HTMLButtonElement)) {
    return null;
  }

  const state = {
    searchForm,
    results,
    message,
    searchButton,
    loadMoreButton,
    selectedTrack,
    assignButton,
    initialized: false,
    nextOffset: 0,
    queryKey: '',
    controller: null,
    seenTrackIds: new Set(),
  };
  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    void searchSpotifyTracks(dialog, false);
  });
  loadMoreButton.addEventListener('click', () => void searchSpotifyTracks(dialog, true));
  spotifyDialogStates.set(dialog, state);

  return state;
}

function openSpotifyMatchDialog(dialog) {
  const state = spotifyDialogState(dialog);
  if (state === null || state.initialized) {
    return;
  }

  state.initialized = true;
  void searchSpotifyTracks(dialog, false);
}

async function searchSpotifyTracks(dialog, append) {
  const state = spotifyDialogState(dialog);
  if (state === null) {
    return;
  }

  const formData = new FormData(state.searchForm);
  const artist = String(formData.get('artist') ?? '').trim();
  const title = String(formData.get('title') ?? '').trim();
  if (artist === '' && title === '') {
    showSpotifySearchMessage(state, 'Künstler oder Songtitel ist erforderlich.', true);
    return;
  }

  const queryKey = `${artist}\n${title}`;
  const shouldAppend = append && state.queryKey === queryKey;
  const offset = shouldAppend ? state.nextOffset : 0;
  if (!shouldAppend) {
    state.results.replaceChildren();
    state.seenTrackIds.clear();
    state.selectedTrack.value = '';
    state.assignButton.disabled = true;
  }

  state.controller?.abort();
  const controller = new AbortController();
  state.controller = controller;
  state.queryKey = queryKey;
  state.loadMoreButton.hidden = true;
  setSpotifySearchLoading(state, true);
  showSpotifySearchMessage(state, 'Spotify wird durchsucht ...');

  try {
    const { requestSpotifySearch, spotifyMetadata } = await spotifySearchHelpers;
    const payload = await requestSpotifySearch({
      origin: window.location.origin,
      artist,
      title,
      offset,
      signal: controller.signal,
    });
    let renderedCount = 0;
    for (const item of payload.items) {
      renderedCount += renderSpotifyResult(dialog, state, item, spotifyMetadata) ? 1 : 0;
    }

    state.nextOffset = payload.offset + payload.limit;
    state.loadMoreButton.hidden = !payload.hasMore;
    if (state.results.children.length === 0) {
      showSpotifySearchMessage(state, 'Keine Spotify-Treffer gefunden.');
    } else if (shouldAppend) {
      showSpotifySearchMessage(state, `${renderedCount} weitere Treffer geladen.`);
    } else {
      showSpotifySearchMessage(state, `${renderedCount} Treffer geladen.`);
    }
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      return;
    }
    state.loadMoreButton.hidden = !shouldAppend;
    const message = error instanceof Error ? error.message : 'Spotify-Suche fehlgeschlagen.';
    showSpotifySearchMessage(state, message, true);
  } finally {
    if (state.controller === controller) {
      state.controller = null;
      setSpotifySearchLoading(state, false);
    }
  }
}

function setSpotifySearchLoading(state, isLoading) {
  state.searchButton.disabled = isLoading;
  state.loadMoreButton.disabled = isLoading;
  state.results.setAttribute('aria-busy', String(isLoading));
}

function showSpotifySearchMessage(state, message, isError = false) {
  state.message.textContent = message;
  state.message.classList.toggle('spotify-search-error', isError);
}

function renderSpotifyResult(dialog, state, item, metadataFormatter) {
  if (item === null || typeof item !== 'object' || typeof item.id !== 'string'
    || typeof item.title !== 'string' || state.seenTrackIds.has(item.id)) {
    return false;
  }
  state.seenTrackIds.add(item.id);

  const result = document.createElement('article');
  result.className = 'spotify-result';
  const choice = document.createElement('label');
  choice.className = 'spotify-result-choice';
  const radio = document.createElement('input');
  radio.type = 'radio';
  radio.name = `spotify-result-${dialog.id}`;
  radio.value = item.id;
  radio.addEventListener('change', () => selectSpotifyResult(state, result, item.id));

  const cover = spotifyCover(item.image_url, item.title);
  const copy = document.createElement('span');
  copy.className = 'spotify-result-copy';
  if (item.id === dialog.dataset.currentTrackId) {
    const badge = document.createElement('span');
    badge.className = 'current-match-badge';
    badge.textContent = dialog.dataset.currentTrackLabel ?? 'Aktuelle Zuordnung';
    copy.append(badge);
  }
  const title = document.createElement('strong');
  title.textContent = item.title;
  const artist = document.createElement('span');
  artist.textContent = typeof item.artist === 'string' ? item.artist : '';
  const metadata = document.createElement('small');
  metadata.textContent = metadataFormatter(item);
  copy.append(title, artist, metadata);
  choice.append(radio, cover, copy);

  const link = document.createElement('a');
  link.className = 'spotify-result-link';
  link.href = `https://open.spotify.com/track/${encodeURIComponent(item.id)}`;
  link.target = '_blank';
  link.rel = 'noreferrer';
  link.textContent = 'In Spotify öffnen';
  result.append(choice, link);
  state.results.append(result);

  return true;
}

function spotifyCover(imageUrl, title) {
  if (typeof imageUrl === 'string') {
    try {
      const url = new URL(imageUrl);
      if (url.protocol === 'https:' && url.hostname === 'i.scdn.co') {
        const image = document.createElement('img');
        image.className = 'spotify-result-cover';
        image.src = url.toString();
        image.alt = '';
        image.loading = 'lazy';
        return image;
      }
    } catch {
      // Fall through to the stable placeholder.
    }
  }

  const placeholder = document.createElement('span');
  placeholder.className = 'spotify-result-cover spotify-result-cover-placeholder';
  placeholder.textContent = title.slice(0, 1).toUpperCase();
  placeholder.setAttribute('aria-hidden', 'true');
  return placeholder;
}

function selectSpotifyResult(state, result, trackId) {
  for (const row of state.results.querySelectorAll('.spotify-result-selected')) {
    row.classList.remove('spotify-result-selected');
  }
  result.classList.add('spotify-result-selected');
  state.selectedTrack.value = trackId;
  state.assignButton.disabled = false;
}

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) {
    return;
  }

  const reveal = event.target.closest('[data-confirm-reveal]');
  if (reveal instanceof HTMLButtonElement) {
    const targetId = reveal.getAttribute('aria-controls');
    const confirmation = targetId === null ? null : document.getElementById(targetId);
    if (confirmation instanceof HTMLElement) {
      reveal.hidden = true;
      confirmation.hidden = false;
      confirmation.querySelector('button')?.focus();
    }
    return;
  }

  const cancel = event.target.closest('[data-confirm-cancel]');
  const confirmation = cancel?.closest('.inline-confirmation');
  if (cancel instanceof HTMLButtonElement && confirmation instanceof HTMLElement) {
    confirmation.hidden = true;
    const owner = confirmation.parentElement;
    const revealButton = owner?.querySelector('[data-confirm-reveal]');
    if (revealButton instanceof HTMLButtonElement) {
      revealButton.hidden = false;
      revealButton.focus();
    }
  }
});

for (const dialog of document.querySelectorAll('[data-spotify-match-dialog]')) {
  if (!(dialog instanceof HTMLDialogElement)) {
    continue;
  }
  spotifyDialogState(dialog);
  dialog.addEventListener('close', () => {
    for (const confirmation of dialog.querySelectorAll('.inline-confirmation')) {
      if (confirmation instanceof HTMLElement) {
        confirmation.hidden = true;
        const reveal = confirmation.parentElement?.querySelector('[data-confirm-reveal]');
        if (reveal instanceof HTMLButtonElement) {
          reveal.hidden = false;
        }
      }
    }
  });
}

for (const form of document.querySelectorAll('[data-ignore-form]')) {
  if (!(form instanceof HTMLFormElement)) {
    continue;
  }

  const impact = form.querySelector('[data-scope-impact]');
  const submit = form.querySelector('[data-ignore-submit]');
  const playlistName = form.dataset.playlistName ?? '';
  const updateScope = () => {
    const selected = form.querySelector('input[name="scope"]:checked');
    const isGlobal = selected instanceof HTMLInputElement && selected.value === 'global';
    if (impact instanceof HTMLElement) {
      impact.textContent = isGlobal
        ? 'Gilt für alle bestehenden und zukünftigen Playlists.'
        : `Gilt nur für „${playlistName}“.`;
    }
    if (submit instanceof HTMLButtonElement) {
      submit.textContent = isGlobal ? 'Global ignorieren' : 'Song ignorieren';
    }
  };

  form.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.name === 'scope') {
      updateScope();
    }
  });
  updateScope();
}

for (const input of document.querySelectorAll('[data-character-input]')) {
  if (!(input instanceof HTMLTextAreaElement)) {
    continue;
  }
  const counter = input.parentElement?.querySelector('[data-character-count]');
  const updateCounter = () => {
    if (counter instanceof HTMLElement) {
      counter.textContent = `${input.value.length} / 500`;
    }
  };
  input.addEventListener('input', updateCounter);
  updateCounter();
}

for (const form of document.querySelectorAll('form[data-confirm]')) {
  if (!(form instanceof HTMLFormElement)) {
    continue;
  }
  form.addEventListener('submit', (event) => {
    const message = form.dataset.confirm;
    if (message !== undefined && !window.confirm(message)) {
      event.preventDefault();
    }
  });
}

for (const input of document.querySelectorAll('[data-auto-submit]')) {
  if (input instanceof HTMLInputElement && input.form !== null) {
    input.addEventListener('change', () => input.form?.requestSubmit());
  }
}
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import test from 'node:test';

class FakeClassList {
  constructor(element) {
    this.element = element;
  }

  add(...names) {
    for (const name of names) {
      this.element.classes.add(name);
    }
  }

  remove(...names) {
    for (const name of names) {
      this.element.classes.delete(name);
    }
  }

  contains(name) {
    return this.element.classes.has(name);
  }

  toggle(name, force) {
    const enabled = force ?? !this.contains(name);
    if (enabled) {
      this.add(name);
    } else {
      this.remove(name);
    }
    return enabled;
  }
}

class FakeElement {
  constructor(tagName = 'div') {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.parentElement = null;
    this.dataset = {};
    this.attributes = new Map();
    this.listeners = new Map();
    this.classes = new Set();
    this.classList = new FakeClassList(this);
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.value = '';
    this.focusCount = 0;
  }

  get className() {
    return [...this.classes].join(' ');
  }

  set className(value) {
    this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
  }

  append(...children) {
    for (const child of children) {
      child.parentElement = this;
      this.children.push(child);
    }
  }

  replaceChildren(...children) {
    this.children = [];
    this.append(...children);
  }

  addEventListener(name, listener) {
    const listeners = this.listeners.get(name) ?? [];
    listeners.push(listener);
    this.listeners.set(name, listeners);
  }

  dispatch(name, event = {}) {
    const dispatchedEvent = {
      target: this,
      preventDefault() {},
      ...event,
    };
    for (const listener of this.listeners.get(name) ?? []) {
      listener(dispatchedEvent);
    }
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  focus() {
    this.focusCount += 1;
  }

  matches(selector) {
    if (selector.startsWith('.')) {
      return this.classList.contains(selector.slice(1));
    }
    if (selector === 'button') {
      return this.tagName === 'BUTTON';
    }
    if (selector === 'dialog') {
      return this.tagName === 'DIALOG';
    }
    if (selector === 'details') {
      return this.tagName === 'DETAILS';
    }
    const dataAttribute = selector.match(/^\[data-([a-z-]+)\]$/);
    if (dataAttribute !== null) {
      const key = dataAttribute[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      return Object.hasOwn(this.dataset, key);
    }

    return false;
  }

  closest(selector) {
    let element = this;
    while (element !== null) {
      if (element.matches(selector)) {
        return element;
      }
      element = element.parentElement;
    }
    return null;
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  }

  querySelectorAll(selector) {
    const matches = [];
    for (const child of this.children) {
      if (child.matches(selector)) {
        matches.push(child);
      }
      matches.push(...child.querySelectorAll(selector));
    }
    return matches;
  }
}

class FakeButton extends FakeElement {
  constructor() {
    super('button');
  }
}

class FakeInput extends FakeElement {
  constructor() {
    super('input');
    this.type = 'text';
    this.name = '';
  }
}

class FakeTextArea extends FakeElement {
  constructor() {
    super('textarea');
  }
}

class FakeForm extends FakeElement {
  constructor(values = {}) {
    super('form');
    this.values = values;
  }
}

class FakeDialog extends FakeElement {
  constructor(id = '') {
    super('dialog');
    this.id = id;
    this.closeCount = 0;
    this.showCount = 0;
    this.selectorMap = new Map();
  }

  querySelector(selector) {
    return this.selectorMap.get(selector) ?? super.querySelector(selector);
  }

  getBoundingClientRect() {
    return { left: 100, right: 500, top: 100, bottom: 500 };
  }

  close() {
    this.closeCount += 1;
  }

  showModal() {
    this.showCount += 1;
  }
}

class FakeDocument {
  constructor(dialogs = []) {
    this.dialogs = dialogs;
    this.listeners = new Map();
    this.elementsById = new Map(dialogs.map((dialog) => [dialog.id, dialog]));
  }

  addEventListener(name, listener) {
    const listeners = this.listeners.get(name) ?? [];
    listeners.push(listener);
    this.listeners.set(name, listeners);
  }

  dispatch(name, target, event = {}) {
    for (const listener of this.listeners.get(name) ?? []) {
      listener({ target, ...event });
    }
  }

  querySelectorAll(selector) {
    if (selector === '.app-dialog') {
      return this.dialogs;
    }
    if (selector === '[data-spotify-match-dialog]') {
      return this.dialogs.filter((dialog) => Object.hasOwn(dialog.dataset, 'spotifyMatchDialog'));
    }
    return [];
  }

  getElementById(id) {
    return this.elementsById.get(id) ?? null;
  }

  createElement(tagName) {
    if (tagName === 'button') {
      return new FakeButton();
    }
    if (tagName === 'input') {
      return new FakeInput();
    }
    return new FakeElement(tagName);
  }
}

class FakeFormData {
  constructor(form) {
    this.values = form.values;
  }

  get(name) {
    return this.values[name] ?? null;
  }
}

function spotifyDialogFixture() {
  const dialog = new FakeDialog('spotify-match-42');
  dialog.dataset.spotifyMatchDialog = '';
  dialog.dataset.currentTrackId = 'track000001';
  dialog.dataset.currentTrackLabel = 'Bisheriger Vorschlag';
  const searchForm = new FakeForm({ artist: 'Artist', title: 'Song' });
  const results = new FakeElement();
  const message = new FakeElement();
  const searchButton = new FakeButton();
  const loadMoreButton = new FakeButton();
  loadMoreButton.hidden = true;
  const selectedTrack = new FakeInput();
  const assignButton = new FakeButton();
  assignButton.disabled = true;
  dialog.selectorMap = new Map([
    ['[data-spotify-search-form]', searchForm],
    ['[data-spotify-results]', results],
    ['[data-spotify-search-message]', message],
    ['[data-spotify-search-submit]', searchButton],
    ['[data-spotify-load-more]', loadMoreButton],
    ['[data-selected-track]', selectedTrack],
    ['[data-assign-track]', assignButton],
  ]);

  return {
    dialog,
    searchForm,
    results,
    message,
    loadMoreButton,
    selectedTrack,
    assignButton,
  };
}

async function executeApp(document, helpers = {}) {
  const context = vm.createContext({
    console,
    document,
    window: { location: { origin: 'https://app.example' } },
    URL,
    Element: FakeElement,
    HTMLElement: FakeElement,
    HTMLButtonElement: FakeButton,
    HTMLDialogElement: FakeDialog,
    HTMLFormElement: FakeForm,
    HTMLInputElement: FakeInput,
    HTMLTextAreaElement: FakeTextArea,
    FormData: FakeFormData,
    AbortController,
    DOMException,
    Error,
    WeakMap,
    Set,
    __spotifySearchHelpers: helpers,
  });
  const appPath = new URL('../../public/assets/app.js', import.meta.url);
  const source = await readFile(appPath, 'utf8');
  const instrumentedSource = source.replace(
    "const spotifySearchHelpers = import('./spotify-match.mjs');",
    'const spotifySearchHelpers = Promise.resolve(globalThis.__spotifySearchHelpers);',
  );
  assert.notEqual(instrumentedSource, source);
  new vm.Script(instrumentedSource, { filename: appPath.pathname }).runInContext(context);

  return context;
}

async function flushAsyncWork() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

test('dialog backdrop handling ignores keyboard activation and inside clicks', async () => {
  const dialog = new FakeDialog('plain-dialog');
  const document = new FakeDocument([dialog]);
  await executeApp(document);

  const click = dialog.listeners.get('click')[0];
  click({ target: new FakeButton(), clientX: 0, clientY: 0 });
  click({ target: dialog, clientX: 200, clientY: 200 });
  assert.equal(dialog.closeCount, 0);

  click({ target: dialog, clientX: 0, clientY: 0 });
  assert.equal(dialog.closeCount, 1);
});

test('searches on open, requires explicit selection and retries pagination inline', async () => {
  const fixture = spotifyDialogFixture();
  const document = new FakeDocument([fixture.dialog]);
  const requests = [];
  const responses = [{
    items: [
      { id: 'track000001', title: 'Song', artist: 'Artist', duration_ms: 180_000 },
      { id: 'track000002', title: 'Song Live', artist: 'Artist', duration_ms: 185_000 },
    ],
    offset: 0,
    limit: 10,
    hasMore: true,
  }, new Error('Spotify vorübergehend nicht erreichbar.'), {
    items: [
      { id: 'track000001', title: 'Song', artist: 'Artist', duration_ms: 180_000 },
      { id: 'track000003', title: 'Song Remaster', artist: 'Artist', duration_ms: 181_000 },
    ],
    offset: 10,
    limit: 10,
    hasMore: false,
  }];
  await executeApp(document, {
    async requestSpotifySearch(request) {
      requests.push(request);
      const response = responses.shift();
      if (response instanceof Error) {
        throw response;
      }
      return response;
    },
    spotifyMetadata(item) {
      return `${item.duration_ms} ms`;
    },
  });
  const trigger = new FakeButton();
  trigger.dataset.dialogTarget = fixture.dialog.id;

  document.dispatch('click', trigger);
  await flushAsyncWork();

  assert.equal(fixture.dialog.showCount, 1);
  assert.deepEqual(requests.map(({ artist, title, offset }) => ({ artist, title, offset })), [
    { artist: 'Artist', title: 'Song', offset: 0 },
  ]);
  assert.equal(fixture.results.children.length, 2);
  assert.equal(fixture.selectedTrack.value, '');
  assert.equal(fixture.assignButton.disabled, true);
  assert.equal(fixture.loadMoreButton.hidden, false);
  assert.equal(fixture.message.textContent, '2 Treffer geladen.');
  assert.equal(
    fixture.results.children[0].querySelector('.current-match-badge').textContent,
    'Bisheriger Vorschlag',
  );
  assert.equal(
    fixture.results.children[0].children[1].href,
    'https://open.spotify.com/track/track000001',
  );

  const secondRadio = fixture.results.children[1].children[0].children[0];
  secondRadio.dispatch('change');
  assert.equal(fixture.selectedTrack.value, 'track000002');
  assert.equal(fixture.assignButton.disabled, false);
  assert.equal(fixture.results.children[1].classList.contains('spotify-result-selected'), true);

  fixture.loadMoreButton.dispatch('click');
  await flushAsyncWork();
  assert.equal(fixture.results.children.length, 2);
  assert.equal(fixture.loadMoreButton.hidden, false);
  assert.equal(fixture.message.textContent, 'Spotify vorübergehend nicht erreichbar.');
  assert.equal(fixture.message.classList.contains('spotify-search-error'), true);

  fixture.loadMoreButton.dispatch('click');
  await flushAsyncWork();
  assert.equal(fixture.results.children.length, 3);
  assert.equal(fixture.loadMoreButton.hidden, true);
  assert.equal(fixture.message.textContent, '1 weitere Treffer geladen.');
  assert.deepEqual(requests.map(({ offset }) => offset), [0, 10, 10]);
});

test('manual search keeps edited values on failure and rejects empty fields locally', async () => {
  const fixture = spotifyDialogFixture();
  const document = new FakeDocument([fixture.dialog]);
  let requests = 0;
  await executeApp(document, {
    async requestSpotifySearch() {
      requests += 1;
      throw new Error('Suche fehlgeschlagen.');
    },
    spotifyMetadata() {
      return '';
    },
  });

  fixture.searchForm.values.artist = 'Korrigierter Artist';
  fixture.searchForm.values.title = '';
  fixture.searchForm.dispatch('submit');
  await flushAsyncWork();
  assert.equal(requests, 1);
  assert.equal(fixture.searchForm.values.artist, 'Korrigierter Artist');
  assert.equal(fixture.message.textContent, 'Suche fehlgeschlagen.');

  fixture.searchForm.values.artist = ' ';
  fixture.searchForm.values.title = '';
  fixture.searchForm.dispatch('submit');
  await flushAsyncWork();
  assert.equal(requests, 1);
  assert.equal(fixture.message.textContent, 'Künstler oder Songtitel ist erforderlich.');
});

test('inline confirmation reveals, cancels and resets when the dialog closes', async () => {
  const dialog = new FakeDialog('spotify-match-42');
  dialog.dataset.spotifyMatchDialog = '';
  const reveal = new FakeButton();
  reveal.dataset.confirmReveal = '';
  reveal.setAttribute('aria-controls', 'ignore-confirm-42');
  const confirmation = new FakeElement();
  confirmation.className = 'inline-confirmation';
  confirmation.hidden = true;
  const cancel = new FakeButton();
  cancel.dataset.confirmCancel = '';
  confirmation.append(cancel);
  const owner = new FakeElement();
  owner.append(reveal, confirmation);
  dialog.append(owner);
  const document = new FakeDocument([dialog]);
  document.elementsById.set('ignore-confirm-42', confirmation);
  await executeApp(document);

  document.dispatch('click', reveal);
  assert.equal(reveal.hidden, true);
  assert.equal(confirmation.hidden, false);
  assert.equal(cancel.focusCount, 1);

  document.dispatch('click', cancel);
  assert.equal(reveal.hidden, false);
  assert.equal(confirmation.hidden, true);
  assert.equal(reveal.focusCount, 1);

  document.dispatch('click', reveal);
  dialog.dispatch('close');
  assert.equal(reveal.hidden, false);
  assert.equal(confirmation.hidden, true);
});
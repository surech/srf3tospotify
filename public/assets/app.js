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

    if (isOutside) {
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
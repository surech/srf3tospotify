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
    dialog.showModal();
  }
});

for (const dialog of document.querySelectorAll('.play-history-dialog')) {
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
/** Named dialogs open and close from browser events, including Livewire dispatches. */
export function listenForNamedDialogs(target = window) {
  const find = name =>
    [...document.querySelectorAll('dialog[data-fruit-dialog]')].find(dialog => dialog.dataset.fruitDialog === name);
  const open = event => {
    const dialog = find(event.detail?.name);
    if (dialog && !dialog.open) dialog.showModal();
  };
  const close = event => {
    const dialog = find(event.detail?.name);
    if (dialog?.open) dialog.close(event.detail?.returnValue);
  };
  target.addEventListener('fruit-dialog-open', open);
  target.addEventListener('fruit-dialog-close', close);
  return () => {
    target.removeEventListener('fruit-dialog-open', open);
    target.removeEventListener('fruit-dialog-close', close);
  };
}

/** Open state for a dialog bound with wire:model or x-model; closing it natively updates the model. */
export function fruitDialogModel() {
  return {
    open: false,
    init() {
      const dialog = this.$el;
      const sync = () => {
        if (this.open && !dialog.open) dialog.showModal();
        else if (!this.open && dialog.open) dialog.close();
      };
      this.$watch('open', sync);
      dialog.addEventListener('close', () => {
        this.open = false;
      });
      this.$nextTick(sync);
    },
  };
}

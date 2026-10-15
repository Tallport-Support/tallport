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

/**
 * Escape in a same-origin frame inside a modal dialog (a text or HTML preview) closes the dialog, as
 * it does from the dialog itself; keys go to the frame's own window otherwise. A cross-origin frame
 * or a plugin, such as the browser's PDF viewer, keeps its keys.
 */
export function listenForDialogFrames(target = document) {
  const load = event => {
    const frame = event.target;
    if (!(frame instanceof HTMLIFrameElement) || !frame.closest('dialog')) return;
    let view;
    try {
      view = frame.contentWindow;
      if (!view?.document) return;
    } catch {
      return;
    }
    view.addEventListener('keydown', key => {
      const dialog = frame.closest('dialog');
      if (key.key !== 'Escape' || key.defaultPrevented || !dialog?.matches(':modal')) return;
      key.preventDefault();
      if (typeof dialog.requestClose === 'function') dialog.requestClose();
      else dialog.close();
    });
  };
  target.addEventListener('load', load, true);
  return () => target.removeEventListener('load', load, true);
}

const TONES = ['default', 'danger'];

/**
 * Ask before an action. Resolves true when the person confirms and false when they cancel or press
 * Escape. The page's confirmer (x-fruit::confirmer, or x-data="fruitConfirmer") asks; without one,
 * the browser's own confirm() does.
 */
export function confirm({ title, message = '', confirm: confirmLabel, cancel, tone = 'default' } = {}) {
  if (typeof title !== 'string' || !title.trim()) throw new Error('FruitUI confirm needs a title.');
  if (!TONES.includes(tone)) throw new Error(`FruitUI confirm tone must be one of: ${TONES.join(', ')}.`);
  return new Promise(resolve => {
    const detail = { title, message, confirm: confirmLabel, cancel, tone, resolve, handled: false };
    window.dispatchEvent(new CustomEvent('fruit-confirm', { detail }));
    if (!detail.handled) resolve(window.confirm(message ? `${title}\n\n${message}` : title));
  });
}

/**
 * The confirmation outlet on a native dialog, rendered once per layout. Requests wait their turn;
 * a destructive request focuses Cancel first, any other focuses the confirm button.
 */
export function fruitConfirmer() {
  const queue = [];
  const controller = new AbortController();
  let current = null;
  return {
    request: { title: '', message: '', confirm: '', cancel: '', tone: 'default' },
    init() {
      const dialog = this.$el;
      const options = { signal: controller.signal };
      window.addEventListener(
        'fruit-confirm',
        event => {
          event.detail.handled = true;
          queue.push(event.detail);
          if (!current) this.next();
        },
        options,
      );
      dialog.addEventListener(
        'close',
        () => {
          const answered = current;
          current = null;
          answered?.resolve(dialog.returnValue === 'confirm');
          this.next();
        },
        options,
      );
    },
    next() {
      current = queue.shift() ?? null;
      if (!current) return;
      const dialog = this.$el;
      const labels = dialog.dataset;
      this.request = {
        title: current.title,
        message: current.message,
        confirm: current.confirm || labels.fruitConfirmLabel || 'OK',
        cancel: current.cancel || labels.fruitCancelLabel || 'Cancel',
        tone: current.tone,
      };
      dialog.returnValue = '';
      this.$nextTick(() => {
        dialog.showModal();
        dialog.querySelector(`button[value="${current.tone === 'danger' ? 'cancel' : 'confirm'}"]`)?.focus();
      });
    },
    destroy() {
      controller.abort();
      for (const request of [current, ...queue]) request?.resolve(false);
    },
  };
}

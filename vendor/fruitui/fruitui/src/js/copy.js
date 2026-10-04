import { fruitMessage } from './messages.js';

/** Copy text to the clipboard; pages served without HTTPS fall back to the selection command. */
export async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.cssText = 'position: fixed; inset-block-start: 0; opacity: 0';
  document.body.append(area);
  area.select();
  const copied = document.execCommand('copy');
  area.remove();
  if (!copied) throw new Error('FruitUI could not copy to the clipboard.');
}

/**
 * A copy button (x-fruit::copy-button): copies the button's data-fruit-copy text, shows "Copied"
 * for a moment and announces the result. A bubbling fruit-copied event follows a successful copy.
 */
export function fruitCopy() {
  let timer;
  const controller = new AbortController();
  return {
    init() {
      const root = this.$el;
      const button = root.querySelector('button[data-fruit-copy]');
      const status = root.querySelector('[role="status"]');
      if (!button || !status) return;
      const announce = text => {
        // Clear first, so a second copy is announced again.
        status.textContent = '';
        requestAnimationFrame(() => (status.textContent = text));
      };
      button.addEventListener(
        'click',
        async () => {
          clearTimeout(timer);
          const value = button.dataset.fruitCopy ?? '';
          try {
            await copyText(value);
            root.dataset.copied = '';
            announce(fruitMessage(root, 'copied-message', 'Copied'));
            button.dispatchEvent(new CustomEvent('fruit-copied', { bubbles: true, detail: { value } }));
          } catch {
            delete root.dataset.copied;
            announce(fruitMessage(root, 'failed-message', 'Could not copy'));
          }
          timer = setTimeout(() => {
            delete root.dataset.copied;
            status.textContent = '';
          }, 2000);
        },
        { signal: controller.signal },
      );
    },
    destroy() {
      clearTimeout(timer);
      controller.abort();
    },
  };
}

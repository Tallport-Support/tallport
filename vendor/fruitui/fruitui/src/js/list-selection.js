/**
 * Multiple selection in an item list, as in mail apps, without showing checkboxes. Each item keeps
 * its native checkbox (the value, wire:model and x-model stay there); this helper checks them:
 *
 * - Cmd/Ctrl+click toggles an item (the open item joins the first time), Shift+click selects a
 *   range, and a plain click clears the selection before the row's own action runs.
 * - On a focused row, Shift+Up/Down extends or shrinks the selection, Cmd/Ctrl+A selects all and
 *   Escape clears.
 * - A [data-fruit-select-toggle] button whose aria-controls names the list shows the checkboxes
 *   (for touch and assistive technology); then a plain click toggles too. A checkbox reached by
 *   Tab is shown while it has focus.
 */
export function fruitListSelection() {
  const controller = new AbortController();
  let list,
    observer,
    anchor = null,
    selecting = false;
  const checkbox = item =>
    item.querySelector(':scope > .f-check input[type="checkbox"], :scope > input[type="checkbox"]');
  const row = item => item.querySelector(':scope > .f-item-row');
  const items = () => [...list.children].filter(item => checkbox(item) && row(item));
  const isChecked = item => checkbox(item).checked;
  const checked = () => items().filter(isChecked);
  const current = () => items().find(item => row(item).matches('[aria-current="true"], [aria-current="page"]'));
  // A click sends the checkbox's own input and change events, so every binding updates.
  const set = (item, on) => {
    const box = checkbox(item);
    if (box && !box.disabled && box.checked !== on) box.click();
  };
  const range = (from, to) => {
    const all = items();
    const [start, end] = [all.indexOf(from), all.indexOf(to)].sort((a, b) => a - b);
    return all.slice(start, end + 1);
  };
  const itemOf = (target, { inRow = true } = {}) => {
    const item = target.closest?.('li');
    if (!item || item.parentElement !== list || !checkbox(item) || !row(item)) return null;
    return !inRow || row(item).contains(target) ? item : null;
  };
  const toggles = () =>
    list.id ? [...document.querySelectorAll(`[data-fruit-select-toggle][aria-controls="${CSS.escape(list.id)}"]`)] : [];
  // Server re-renders reset attributes; the selecting state survives them.
  const reflect = () => {
    if (list.hasAttribute('data-selecting') !== selecting) list.toggleAttribute('data-selecting', selecting);
    for (const toggle of toggles())
      if (toggle.getAttribute('aria-pressed') !== String(selecting))
        toggle.setAttribute('aria-pressed', String(selecting));
  };

  return {
    init() {
      list = this.$el;
      const listen = (node, event, handler, capture = false) =>
        node.addEventListener(event, handler, { capture, signal: controller.signal });
      // Shift+click would otherwise select the rows' text.
      listen(
        list,
        'mousedown',
        event => {
          if (event.shiftKey && itemOf(event.target)) event.preventDefault();
        },
        true,
      );
      // Capture runs before the row's own click handler, which a selection gesture replaces.
      listen(
        list,
        'click',
        event => {
          const item = itemOf(event.target);
          if (!item) return;
          const toggle = event.metaKey || event.ctrlKey || (selecting && !event.shiftKey);
          if (!toggle && !event.shiftKey) {
            for (const other of checked()) set(other, false);
            anchor = item;
            return;
          }
          event.preventDefault();
          event.stopImmediatePropagation();
          if (event.shiftKey) {
            const start = anchor?.isConnected && items().includes(anchor) ? anchor : (current() ?? item);
            for (const other of range(start, item)) set(other, true);
          } else {
            const open = current();
            if (!checked().length && open && open !== item && !selecting) set(open, true);
            set(item, !isChecked(item));
            anchor = item;
          }
          row(item).focus();
        },
        true,
      );
      listen(
        list,
        'keydown',
        event => {
          const item = itemOf(event.target);
          if (!item || event.target !== row(item)) return;
          if (event.shiftKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const all = items();
            const next = all[all.indexOf(item) + (event.key === 'ArrowDown' ? 1 : -1)];
            if (!next) return;
            if (!isChecked(item)) {
              set(item, true);
              set(next, true);
            } else if (isChecked(next)) set(item, false);
            else set(next, true);
            anchor ??= item;
            row(next).focus();
          } else if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'a') {
            event.preventDefault();
            for (const other of items()) set(other, true);
          } else if (event.key === 'Escape' && checked().length) {
            event.preventDefault();
            event.stopImmediatePropagation();
            for (const other of checked()) set(other, false);
          }
        },
        true,
      );
      listen(document, 'click', event => {
        const toggle = event.target.closest?.('[data-fruit-select-toggle]');
        if (!toggle || !list.id || toggle.getAttribute('aria-controls') !== list.id) return;
        selecting = !selecting;
        reflect();
      });
      observer = new MutationObserver(reflect);
      observer.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['data-selecting', 'aria-pressed'],
      });
      reflect();
    },
    destroy() {
      controller.abort();
      observer?.disconnect();
      list?.removeAttribute('data-selecting');
    },
  };
}

import { fruitId } from './control-bridge.js';
import { fruitPopup } from './popup.js';
import { fruitMessage } from './messages.js';

/**
 * A listbox of suggestions for a text control, read from the <datalist> inside root. Autocomplete
 * and Token Field share it. `query()` returns the text to match, or null to close; `pick(option)`
 * applies a choice. With `filter: false` (server search) the options are shown as supplied, since
 * the application already matched them. While the control has focus, the list follows changes to
 * the datalist, such as a Livewire re-render with new results. `messages` names the root's
 * data-fruit-* attributes for the list label and the count, so a widget can keep its own.
 */
export function suggestionList(
  root,
  control,
  {
    query,
    pick,
    filter = true,
    exclude = () => false,
    anchor = control,
    messages = { label: 'label', count: 'count-message' },
  },
) {
  const controller = new AbortController();
  const listen = (node, event, handler, options = {}) =>
    node.addEventListener(event, handler, { ...options, signal: controller.signal });
  const list = document.createElement('ul');
  list.id = fruitId('fruit-suggestions');
  list.className = 'f-autocomplete__options';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', fruitMessage(root, messages.label, 'Suggestions'));
  list.hidden = true;
  const status = document.createElement('span');
  status.className = 'f-sr-only';
  status.setAttribute('role', 'status');
  // Generated UI lives in a wire:ignore mount so Livewire morphs keep it.
  (root.querySelector('[data-fruit-ui]') ?? root).append(list, status);
  const overlay = fruitPopup(list, anchor, { stretch: anchor.tagName !== 'TEXTAREA' });
  let active = -1,
    matches = [];

  const options = () =>
    [...(root.querySelector('datalist')?.options ?? [])].filter(option => !option.disabled && !exclude(option));
  const label = option => option.label || option.value;
  const hide = () => {
    active = -1;
    matches = [];
    list.hidden = true;
    overlay.hide();
    control.removeAttribute('aria-activedescendant');
  };
  const highlight = index => {
    active = index;
    [...list.children].forEach((item, i) => item.setAttribute('aria-selected', String(i === active)));
    const item = list.children[active];
    if (item) {
      control.setAttribute('aria-activedescendant', item.id);
      item.scrollIntoView({ block: 'nearest' });
    } else control.removeAttribute('aria-activedescendant');
  };
  const choose = index => {
    const option = matches[index];
    hide();
    if (option) pick(option);
  };
  const show = () => {
    const text = query();
    if (text === null || control.disabled || control.readOnly) return hide();
    const lower = text.toLocaleLowerCase();
    // A suggestion matches when one of its words, or its value, starts with the query.
    const words = option => `${label(option)} ${option.value}`.toLocaleLowerCase().split(/[\s@:/#._-]+/);
    matches = filter
      ? options()
          .filter(
            option =>
              option.value.toLocaleLowerCase().startsWith(lower) || words(option).some(word => word.startsWith(lower)),
          )
          .sort(
            (a, b) =>
              Number(!label(a).toLocaleLowerCase().startsWith(lower)) -
              Number(!label(b).toLocaleLowerCase().startsWith(lower)),
          )
          .slice(0, 8)
      : options();
    if (!matches.length) {
      status.textContent = fruitMessage(root, 'no-suggestions', 'No suggestions');
      return hide();
    }
    list.replaceChildren(
      ...matches.map((option, index) => {
        const item = document.createElement('li');
        item.id = `${list.id}-${index}`;
        item.className = 'f-autocomplete__option';
        item.setAttribute('role', 'option');
        item.textContent = label(option);
        if (option.label && option.label !== option.value) {
          const detail = document.createElement('span');
          detail.className = 'f-autocomplete__detail';
          detail.textContent = option.value;
          item.append(detail);
        }
        item.addEventListener('pointerdown', event => event.preventDefault());
        item.addEventListener('click', () => choose(index));
        return item;
      }),
    );
    list.hidden = false;
    overlay.show();
    status.textContent = fruitMessage(root, messages.count, '{count} suggestions', {
      count: matches.length,
    });
    highlight(0);
  };

  // A textbox cannot carry aria-expanded; aria-haspopup and the live status describe the list.
  const describe = () => {
    control.setAttribute('aria-autocomplete', 'list');
    control.setAttribute('aria-haspopup', 'listbox');
    control.setAttribute('aria-controls', list.id);
  };
  describe();
  // A server re-render replaces the control's attributes; restore the association. New datalist
  // options (server results) refresh an open list.
  const observer = new MutationObserver(mutations => {
    if (control.getAttribute('aria-controls') !== list.id || !control.hasAttribute('aria-haspopup')) describe();
    const datalistChanged = mutations.some(
      mutation =>
        (mutation.target.nodeType === Node.ELEMENT_NODE ? mutation.target : mutation.target.parentElement)?.closest(
          'datalist',
        ) || [...mutation.addedNodes, ...mutation.removedNodes].some(node => node.nodeName === 'DATALIST'),
    );
    if (datalistChanged && document.activeElement === control) show();
  });
  observer.observe(control, {
    attributes: true,
    attributeFilter: ['aria-autocomplete', 'aria-haspopup', 'aria-controls'],
  });
  observer.observe(root, { childList: true, subtree: true, characterData: true, attributes: true });

  listen(control, 'input', event => {
    if (!event.isComposing) show();
  });
  // Capture runs before the control's own key handling, so Enter picks a suggestion instead.
  listen(
    control,
    'keydown',
    event => {
      if (event.isComposing || list.hidden) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
      } else if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        event.stopImmediatePropagation();
        choose(active);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        hide();
      }
    },
    { capture: true },
  );
  listen(control, 'blur', hide);
  listen(control, 'click', () => {
    if (!list.hidden) show();
  });

  return {
    hide,
    destroy() {
      observer.disconnect();
      controller.abort();
      overlay.destroy();
      list.remove();
      status.remove();
      for (const name of ['aria-autocomplete', 'aria-haspopup', 'aria-controls', 'aria-activedescendant'])
        control.removeAttribute(name);
    },
  };
}

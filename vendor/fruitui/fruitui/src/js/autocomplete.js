import { fruitId, publishValue } from './control-bridge.js';
import { fruitPopup } from './popup.js';
import { fruitMessage } from './messages.js';

/**
 * Suggest completions for the word being typed in a native input or textarea. Options come from
 * a <datalist> inside the root, which the application (or a Livewire re-render) keeps current.
 * With data-fruit-trigger (for example "@"), only words that start with the trigger complete.
 */
export function fruitAutocomplete() {
  let root,
    control,
    list,
    status,
    overlay,
    controller,
    observer,
    active = -1,
    matches = [],
    token = null;
  const trigger = () => root.dataset.fruitTrigger || '';
  const options = () => [...(root.querySelector('datalist')?.options ?? [])].filter(option => !option.disabled);

  /** The word before the caret: its start index and query text, or null. */
  const currentToken = () => {
    const end = control.selectionStart ?? control.value.length;
    const before = control.value.slice(0, end);
    const start = before.search(/[^\s,]*$/);
    const word = before.slice(start);
    const mark = trigger();
    if (mark) return word.startsWith(mark) ? { start, end, query: word.slice(mark.length) } : null;
    return word ? { start, end, query: word } : null;
  };
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
  const show = () => {
    token = currentToken();
    if (!token || control.disabled || control.readOnly) return hide();
    const query = token.query.toLocaleLowerCase();
    const label = option => option.label || option.value;
    // A suggestion matches when one of its words, or its value, starts with the query.
    const words = option => `${label(option)} ${option.value}`.toLocaleLowerCase().split(/[\s@:/#._-]+/);
    const matchesQuery = option =>
      option.value.toLocaleLowerCase().startsWith(trigger() + query) ||
      words(option).some(word => word.startsWith(query));
    matches = options()
      .filter(matchesQuery)
      .sort(
        (a, b) =>
          Number(!label(a).toLocaleLowerCase().startsWith(query)) -
          Number(!label(b).toLocaleLowerCase().startsWith(query)),
      )
      .slice(0, 8);
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
    status.textContent = fruitMessage(root, 'count-message', '{count} suggestions', { count: matches.length });
    highlight(0);
  };
  const choose = index => {
    const option = matches[index];
    if (!option || !token) return;
    // Publishing dispatches input, which recomputes the token; keep this insertion's range.
    const { start, end } = token;
    const value = control.value;
    const insert = `${option.value}${trigger() ? ' ' : ''}`;
    publishValue(control, value.slice(0, start) + insert + value.slice(end));
    const caret = start + insert.length;
    control.setSelectionRange?.(caret, caret);
    control.focus();
    hide();
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('input:not([type="hidden"]), textarea');
      if (!control) return;
      controller = new AbortController();
      const listen = (node, event, handler) => node.addEventListener(event, handler, { signal: controller.signal });
      list = document.createElement('ul');
      list.id = fruitId('fruit-suggestions');
      list.className = 'f-autocomplete__options';
      list.setAttribute('role', 'listbox');
      list.setAttribute('aria-label', fruitMessage(root, 'label', 'Suggestions'));
      list.hidden = true;
      status = document.createElement('span');
      status.className = 'f-sr-only';
      status.setAttribute('role', 'status');
      // Generated UI lives in a wire:ignore mount so Livewire morphs keep it.
      (root.querySelector('[data-fruit-ui]') ?? root).append(list, status);
      overlay = fruitPopup(list, control, { stretch: control.tagName === 'INPUT' });
      // A textbox cannot carry aria-expanded; aria-haspopup and the live status describe the list.
      const describe = () => {
        control.setAttribute('aria-autocomplete', 'list');
        control.setAttribute('aria-haspopup', 'listbox');
        control.setAttribute('aria-controls', list.id);
      };
      describe();
      // A server re-render replaces the control's attributes; restore the association.
      observer = new MutationObserver(() => {
        if (control.getAttribute('aria-controls') !== list.id || !control.hasAttribute('aria-haspopup')) describe();
      });
      observer.observe(control, {
        attributes: true,
        attributeFilter: ['aria-autocomplete', 'aria-haspopup', 'aria-controls'],
      });
      listen(control, 'input', event => {
        if (event.isComposing) return;
        show();
      });
      listen(control, 'keydown', event => {
        if (event.isComposing || list.hidden) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
          event.preventDefault();
          event.stopPropagation();
          choose(active);
        } else if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          hide();
        }
      });
      listen(control, 'blur', hide);
      listen(control, 'click', () => {
        if (!list.hidden) show();
      });
    },
    destroy() {
      observer?.disconnect();
      controller?.abort();
      overlay?.destroy();
      list?.remove();
      status?.remove();
      for (const name of ['aria-autocomplete', 'aria-haspopup', 'aria-controls', 'aria-activedescendant'])
        control?.removeAttribute(name);
    },
  };
}

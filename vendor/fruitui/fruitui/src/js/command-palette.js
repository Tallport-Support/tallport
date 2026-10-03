import { fruitId } from './control-bridge.js';

/**
 * A modal command palette: a dialog with a search field filtering a listbox of command links and
 * buttons. Cmd/Ctrl + data-fruit-shortcut toggles it; Enter activates the highlighted command.
 */
export function fruitCommandPalette() {
  let dialog,
    input,
    list,
    empty,
    controller,
    observer,
    active = -1;
  const options = () =>
    [...list.querySelectorAll('[role="option"]')].filter(
      option => !option.hidden && !option.matches(':disabled') && option.getAttribute('aria-disabled') !== 'true',
    );
  const highlight = index => {
    const visible = options();
    active = visible.length ? (index + visible.length) % visible.length : -1;
    for (const option of list.querySelectorAll('[role="option"]')) option.setAttribute('aria-selected', 'false');
    const option = visible[active];
    if (!option) return input.removeAttribute('aria-activedescendant');
    option.id ||= fruitId('fruit-command');
    option.setAttribute('aria-selected', 'true');
    input.setAttribute('aria-activedescendant', option.id);
    option.scrollIntoView({ block: 'nearest' });
  };
  const filter = () => {
    const query = input.value.trim().toLocaleLowerCase();
    for (const option of list.querySelectorAll('[role="option"]'))
      option.hidden = Boolean(query) && !option.textContent.toLocaleLowerCase().includes(query);
    for (const group of list.querySelectorAll('[role="group"]'))
      group.hidden = !group.querySelector('[role="option"]:not([hidden])');
    empty.hidden = options().length > 0;
    highlight(0);
  };
  const activate = option => {
    if (!option) return;
    dialog.close();
    option.click();
  };
  return {
    init() {
      dialog = this.$el;
      input = dialog.querySelector('[role="combobox"]');
      list = dialog.querySelector('[role="listbox"]');
      empty = dialog.querySelector('.f-command-palette__empty');
      if (!input || !list || !empty) return;
      controller = new AbortController();
      const listen = (node, event, handler) => node.addEventListener(event, handler, { signal: controller.signal });
      listen(input, 'input', filter);
      listen(input, 'keydown', event => {
        if (event.isComposing) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          highlight(active + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter') {
          event.preventDefault();
          activate(options()[active]);
        }
      });
      listen(list, 'pointermove', event => {
        const option = event.target.closest('[role="option"]');
        if (option && !option.hidden) highlight(options().indexOf(option));
      });
      // Focus stays in the search field, as in Spotlight: pointer presses elsewhere in the palette
      // neither blur it nor focus the dialog; option clicks still activate.
      listen(dialog, 'mousedown', event => {
        if (event.target !== input) event.preventDefault();
      });
      listen(list, 'click', event => {
        if (event.target.closest('[role="option"]') && dialog.open) dialog.close();
      });
      // Each opening (shortcut, named event or wire:model) starts from an empty query.
      observer = new MutationObserver(() => {
        if (!dialog.open) return;
        input.value = '';
        filter();
        input.focus();
      });
      observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
      const shortcut = dialog.dataset.fruitShortcut?.toLocaleLowerCase();
      if (shortcut)
        listen(document, 'keydown', event => {
          if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLocaleLowerCase() === shortcut) {
            event.preventDefault();
            if (dialog.open) dialog.close();
            else dialog.showModal();
          }
        });
      filter();
    },
    destroy() {
      observer?.disconnect();
      controller?.abort();
    },
  };
}

import { bridgeControl, fruitId, publishValue } from './control-bridge.js';
import { fruitPopup, isRtl } from './popup.js';
import { fruitMessage } from './messages.js';

export function fruitCombobox() {
  let root,
    control,
    query,
    list,
    mount,
    ownedMount,
    dispose,
    outside,
    overlay,
    options = [],
    active = -1,
    open = false;
  const label = () => control.selectedOptions[0]?.label || '';
  const hide = () => {
    open = false;
    overlay.hide();
    list.hidden = true;
    query.setAttribute('aria-expanded', 'false');
    query.removeAttribute('aria-activedescendant');
  };
  const highlight = index => {
    active = index;
    [...list.children].forEach((node, i) => node.setAttribute('aria-selected', String(i === active)));
    if (list.children[active]) {
      query.setAttribute('aria-activedescendant', list.children[active].id);
      list.children[active].scrollIntoView({ block: 'nearest' });
    } else query.removeAttribute('aria-activedescendant');
  };
  const show = (filter = '') => {
    if (query.disabled) return;
    options = [...control.options].filter(
      option =>
        !option.matches(':disabled') &&
        !option.hidden &&
        option.label.toLocaleLowerCase().includes(filter.toLocaleLowerCase()),
    );
    list.replaceChildren();
    options.forEach((option, index) => {
      const item = document.createElement('li');
      item.className = 'f-combobox__option';
      item.id = `${list.id}-${index}`;
      item.setAttribute('role', 'option');
      item.textContent = option.label;
      item.addEventListener('pointerdown', event => event.preventDefault());
      item.addEventListener('click', () => choose(index));
      list.append(item);
    });
    if (!options.length) {
      const empty = document.createElement('li');
      empty.className = 'f-combobox__empty';
      empty.setAttribute('role', 'presentation');
      empty.textContent = fruitMessage(root, 'no-matches', 'No matches');
      list.append(empty);
    }
    open = true;
    list.hidden = false;
    query.setAttribute('aria-expanded', 'true');
    overlay.show();
    const selected = options.findIndex(option => option.selected);
    highlight(options.length ? Math.max(0, selected) : -1);
  };
  const choose = index => {
    if (!options[index]) return;
    publishValue(control, options[index].value);
    query.value = label();
    query.removeAttribute('aria-invalid');
    hide();
    query.focus();
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('select[data-fruit-control]');
      if (!control || control.multiple || control.size > 1) return;
      query = document.createElement('input');
      query.type = 'text';
      query.className = 'f-input';
      query.autocomplete = 'off';
      query.setAttribute('role', 'combobox');
      query.setAttribute('aria-autocomplete', 'list');
      query.setAttribute('aria-expanded', 'false');
      list = document.createElement('ul');
      list.id = fruitId('fruit-options');
      list.className = 'f-combobox__options';
      list.setAttribute('role', 'listbox');
      list.hidden = true;
      mount = this.$el.querySelector('[data-fruit-ui]');
      ownedMount = !mount;
      if (!mount) {
        mount = document.createElement('div');
        mount.setAttribute('data-fruit-ui', '');
        this.$el.append(mount);
      }
      query.setAttribute('aria-controls', list.id);
      mount.append(query, list);
      control.hidden = true;
      overlay = fruitPopup(list, query, { stretch: true });
      query.value = label();
      query.addEventListener('input', () => show(query.value));
      query.addEventListener('click', () => show());
      query.addEventListener('blur', () => {
        hide();
        query.value = label();
      });
      query.addEventListener('keydown', event => {
        if (event.isComposing) return;
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
          event.preventDefault();
          if (!open) {
            show();
            highlight(event.key === 'ArrowUp' ? options.length - 1 : 0);
          } else if (options.length)
            highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
        } else if (event.key === 'Enter' && open && active >= 0) {
          event.preventDefault();
          choose(active);
        } else if (event.key === 'Escape' && open) {
          event.preventDefault();
          event.stopPropagation();
          hide();
          query.value = label();
        } else if (event.key === 'Tab') hide();
      });
      outside = event => {
        if (!this.$el.contains(event.target)) hide();
      };
      document.addEventListener('pointerdown', outside);
      dispose = bridgeControl(this, control, query, reason => {
        if (open && ['value', 'reset'].includes(reason)) hide();
        if (['initial', 'value', 'reset'].includes(reason) || !open) query.value = label();
        if (open && reason === 'options') show(query.value);
        for (const name of ['aria-label', 'aria-labelledby']) {
          if (query.hasAttribute(name)) list.setAttribute(name, query.getAttribute(name));
          else list.removeAttribute(name);
        }
        if (query.disabled) hide();
      });
    },
    destroy() {
      dispose?.();
      overlay?.destroy();
      document.removeEventListener('pointerdown', outside);
      query?.remove();
      list?.remove();
      if (ownedMount) mount?.remove();
      if (control) control.hidden = false;
    },
  };
}

/** A newline-delimited native textarea value; applications validate each token. */
export function fruitTokenField() {
  let root,
    control,
    entry,
    query,
    status,
    mount,
    ownedMount,
    dispose,
    resetPending,
    tokens = [];
  const parse = () =>
    control.value
      .split(/\r?\n/)
      .map(value => value.trim())
      .filter(Boolean);
  const announce = text => {
    status.textContent = text;
  };
  const render = () => {
    tokens = parse();
    entry.querySelectorAll('.f-chip').forEach(chip => chip.remove());
    for (const [index, token] of tokens.entries()) {
      const chip = document.createElement('span');
      chip.className = 'f-chip';
      const text = document.createElement('span');
      text.textContent = token;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'f-chip__remove';
      remove.textContent = '×';
      remove.setAttribute('aria-label', fruitMessage(root, 'remove-label', 'Remove {value}', { value: token }));
      remove.disabled = query.disabled || query.readOnly;
      remove.addEventListener('click', () => {
        publishValue(control, tokens.filter((_, i) => i !== index).join('\n'));
        announce(fruitMessage(root, 'removed-message', 'Removed {value}', { value: token }));
        query.focus();
      });
      remove.addEventListener('keydown', event => {
        const buttons = [...entry.querySelectorAll('button')];
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          (buttons[index + ((event.key === 'ArrowLeft') !== isRtl(entry) ? -1 : 1)] || query).focus();
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          query.focus();
        }
      });
      chip.append(text, remove);
      entry.insertBefore(chip, query);
    }
  };
  const add = text => {
    if (query.disabled || query.readOnly) return false;
    const next = [...tokens];
    for (const value of text
      .split(/[,\n]/)
      .map(value => value.trim())
      .filter(Boolean)) {
      const detail = { value, error: fruitMessage(root, 'invalid-message', 'Check this value before adding it.') };
      if (!control.dispatchEvent(new CustomEvent('fruit-token-add', { bubbles: true, cancelable: true, detail }))) {
        query.setCustomValidity(detail.error);
        query.setAttribute('aria-invalid', 'true');
        announce(detail.error);
        return false;
      }
      const normalized = String(detail.value).trim();
      if (normalized && !next.includes(normalized)) next.push(normalized);
    }
    if (control.maxLength >= 0 && next.join('\n').length > control.maxLength) {
      const error = fruitMessage(root, 'length-message', 'Use at most {count} characters.', {
        count: control.maxLength,
      });
      query.setCustomValidity(error);
      query.setAttribute('aria-invalid', 'true');
      announce(error);
      return false;
    }
    publishValue(control, next.join('\n'));
    query.value = '';
    query.setCustomValidity('');
    query.removeAttribute('aria-invalid');
    announce(fruitMessage(root, 'count-message', '{count} items', { count: next.length }));
    return true;
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('textarea[data-fruit-control]');
      if (!control) return;
      entry = document.createElement('div');
      entry.className = 'f-token-field__entry';
      query = document.createElement('input');
      query.type = 'text';
      query.autocomplete = 'off';
      query.placeholder = fruitMessage(root, 'placeholder', 'Add an item');
      status = document.createElement('span');
      status.className = 'f-sr-only';
      status.setAttribute('role', 'status');
      mount = this.$el.querySelector('[data-fruit-ui]');
      ownedMount = !mount;
      if (!mount) {
        mount = document.createElement('div');
        mount.setAttribute('data-fruit-ui', '');
        this.$el.append(mount);
      }
      entry.append(query);
      mount.append(entry, status);
      control.hidden = true;
      resetPending = () => {
        query.value = '';
        query.setCustomValidity('');
        query.removeAttribute('aria-invalid');
        status.textContent = '';
        render();
      };
      control.addEventListener('fruit-token-reset', resetPending);
      query.addEventListener('keydown', event => {
        if (event.isComposing) return;
        if (event.key === 'Enter' || event.key === ',') {
          event.preventDefault();
          add(query.value);
        } else if ((event.key === 'Backspace' || event.key === 'ArrowLeft') && !query.value)
          entry.querySelector('.f-chip:last-of-type button')?.focus();
        else if (event.key === 'Escape') {
          query.value = '';
          query.setCustomValidity('');
          query.removeAttribute('aria-invalid');
        } else if (event.key === 'Tab' && query.value.trim()) add(query.value);
      });
      query.addEventListener('input', () => {
        query.setCustomValidity('');
        query.removeAttribute('aria-invalid');
      });
      query.addEventListener('change', () => {
        if (query.value.trim()) add(query.value);
      });
      query.addEventListener('paste', event => {
        const text = event.clipboardData?.getData('text');
        if (text && /[,\n]/.test(text)) {
          event.preventDefault();
          add(text);
        }
      });
      dispose = bridgeControl(
        this,
        control,
        query,
        reason => {
          render();
          if (['value', 'reset'].includes(reason)) {
            query.value = '';
            status.textContent = '';
          }
        },
        { presentation: entry, focusRoot: entry },
      );
    },
    destroy() {
      dispose?.();
      control?.removeEventListener('fruit-token-reset', resetPending);
      entry?.remove();
      status?.remove();
      if (ownedMount) mount?.remove();
      if (control) control.hidden = false;
    },
  };
}

/**
 * A selection bar that follows its data-count attribute, so any script (Alpine, jQuery, plain DOM)
 * updates the count by setting it. Zero hides the bar. The template is Laravel's translated
 * ":count selected", with an optional "one|other" plural form chosen by the page language.
 */
export function fruitSelectionBar() {
  let bar, observer;
  const render = () => {
    const count = Math.max(0, Number.parseInt(bar.dataset.count ?? '0', 10) || 0);
    const forms = (bar.dataset.fruitTemplate || ':count selected').split('|');
    const language = bar.closest('[lang]')?.lang || navigator.language || 'en';
    const form = forms.length > 1 && new Intl.PluralRules(language).select(count) !== 'one' ? forms[1] : forms[0];
    const status = bar.querySelector('.f-selection-bar__count');
    if (status) status.textContent = form.trim().replace(':count', String(count));
    bar.hidden = count === 0;
  };
  return {
    init() {
      bar = this.$el;
      observer = new MutationObserver(render);
      observer.observe(bar, { attributes: true, attributeFilter: ['data-count'] });
      render();
    },
    destroy() {
      observer?.disconnect();
    },
  };
}

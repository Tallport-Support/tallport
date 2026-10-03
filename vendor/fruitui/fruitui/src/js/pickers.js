import { fruitId, publishValue } from './control-bridge.js';
import { fruitPopup, isRtl } from './popup.js';
import { fruitMessage } from './messages.js';

/*
 * Browser picker popups cannot be styled. These helpers keep the native input as the value owner
 * and open a FruitUI popup instead; cancelling the input's click stops the browser's own picker.
 * Without JavaScript the browser picker remains.
 */

const pad = number => String(number).padStart(2, '0');
const isoDate = date =>
  `${String(date.getFullYear()).padStart(4, '0')}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const parseDate = text => {
  const match = /^(\d{4,})-(\d{2})-(\d{2})/.exec(text || '');
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
};
const addDays = (date, days) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
const addMonths = (date, months) => {
  const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
  const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
  target.setDate(Math.min(date.getDate(), last));
  return target;
};
const sameDay = (a, b) => Boolean(a && b) && isoDate(a) === isoDate(b);
const localeOf = element => element.closest('[lang]')?.lang || navigator.language || 'en';

/** The locale's first day of the week, 0 for Sunday. */
function firstDayOfWeek(locale) {
  try {
    const info = new Intl.Locale(locale);
    const week = info.getWeekInfo?.() ?? info.weekInfo;
    if (week?.firstDay) return week.firstDay % 7;
  } catch {
    // Unknown locale: fall through.
  }
  return /^en(-US|-CA)?$/i.test(locale) ? 0 : 1;
}

/** Popup state shared by both pickers: open/close, outside presses, focus leaving, and ARIA state. */
function pickerPopup(root, control, panel, { onOpen, onFocus }) {
  const overlay = fruitPopup(panel, control, { start: true });
  const controller = new AbortController();
  const listen = (node, event, handler) => node.addEventListener(event, handler, { signal: controller.signal });
  const isOpen = () => !panel.hidden;
  // Date and color inputs have no role that allows aria-expanded; the popup takes focus when it matters.
  const describe = () => {
    control.setAttribute('aria-haspopup', 'dialog');
    control.setAttribute('aria-controls', panel.id);
  };
  const close = restoreFocus => {
    if (!isOpen()) return;
    overlay.hide();
    panel.hidden = true;
    describe();
    if (restoreFocus) control.focus();
  };
  const open = moveFocus => {
    if (control.disabled || control.readOnly) return;
    panel.hidden = false;
    onOpen();
    // Position after rendering, and focus once the popup is visible.
    overlay.show();
    describe();
    if (moveFocus) onFocus();
  };
  describe();
  // A server re-render replaces the control's attributes; restore the popup association.
  const observer = new MutationObserver(() => {
    if (control.getAttribute('aria-controls') !== panel.id || !control.hasAttribute('aria-haspopup')) describe();
  });
  observer.observe(control, { attributes: true, attributeFilter: ['aria-haspopup', 'aria-controls'] });
  listen(document, 'pointerdown', event => {
    if (isOpen() && !root.contains(event.target) && !panel.contains(event.target)) close(false);
  });
  listen(root, 'focusout', event => {
    if (isOpen() && event.relatedTarget && !root.contains(event.relatedTarget) && !panel.contains(event.relatedTarget))
      close(false);
  });
  listen(panel, 'keydown', event => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    event.stopPropagation();
    close(true);
  });
  return {
    listen,
    open,
    close,
    isOpen,
    destroy() {
      observer.disconnect();
      controller.abort();
      overlay.destroy();
    },
  };
}

/** A calendar popover for a native date or datetime-local input, following the WAI date picker pattern. */
export function fruitDatePicker() {
  let root, control, panel, title, grid, popup;
  let view = new Date(),
    focused = new Date();
  const selected = () => parseDate(control.value);
  const bounds = () => [parseDate(control.min), parseDate(control.max)];
  const unavailable = date => {
    const [min, max] = bounds();
    return Boolean((min && date < min) || (max && date > max));
  };
  const clamp = date => {
    const [min, max] = bounds();
    return min && date < min ? min : max && date > max ? max : date;
  };
  const render = () => {
    const locale = localeOf(control);
    title.textContent = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(view);
    const start = addDays(view, -((view.getDay() - firstDayOfWeek(locale) + 7) % 7));
    const narrow = new Intl.DateTimeFormat(locale, { weekday: 'narrow' });
    const long = new Intl.DateTimeFormat(locale, { weekday: 'long' });
    const full = new Intl.DateTimeFormat(locale, { dateStyle: 'full' });
    const head = document.createElement('tr');
    for (let index = 0; index < 7; index++) {
      const day = addDays(start, index);
      const cell = document.createElement('th');
      cell.scope = 'col';
      cell.abbr = long.format(day);
      cell.textContent = narrow.format(day);
      head.append(cell);
    }
    const rows = [];
    for (let week = 0; week < 6; week++) {
      const row = document.createElement('tr');
      for (let index = 0; index < 7; index++) {
        const day = addDays(start, week * 7 + index);
        const cell = document.createElement('td');
        cell.setAttribute('aria-selected', String(sameDay(day, selected())));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'f-calendar__day';
        button.tabIndex = sameDay(day, focused) ? 0 : -1;
        button.textContent = String(day.getDate());
        button.dataset.date = isoDate(day);
        button.setAttribute('aria-label', full.format(day));
        if (day.getMonth() !== view.getMonth()) button.dataset.outside = '';
        if (sameDay(day, new Date())) button.setAttribute('aria-current', 'date');
        if (unavailable(day)) button.setAttribute('aria-disabled', 'true');
        cell.append(button);
        row.append(cell);
      }
      rows.push(row);
    }
    grid.tHead.replaceChildren(head);
    grid.tBodies[0].replaceChildren(...rows);
  };
  const show = (date, moveFocus = true) => {
    focused = date;
    view = new Date(date.getFullYear(), date.getMonth(), 1);
    render();
    if (moveFocus) grid.querySelector(`[data-date="${isoDate(date)}"]`)?.focus();
  };
  const choose = date => {
    if (unavailable(date)) return;
    let value = isoDate(date);
    if (control.type === 'datetime-local') {
      const now = new Date();
      value += `T${control.value.split('T')[1] || `${pad(now.getHours())}:${pad(now.getMinutes())}`}`;
    }
    publishValue(control, value);
    popup.close(true);
  };
  const step = (event, date) => {
    const forward = isRtl(control) ? -1 : 1;
    const moves = {
      ArrowLeft: () => addDays(date, -forward),
      ArrowRight: () => addDays(date, forward),
      ArrowUp: () => addDays(date, -7),
      ArrowDown: () => addDays(date, 7),
      Home: () => addDays(date, -((date.getDay() - firstDayOfWeek(localeOf(control)) + 7) % 7)),
      End: () => addDays(date, 6 - ((date.getDay() - firstDayOfWeek(localeOf(control)) + 7) % 7)),
      PageUp: () => addMonths(date, event.shiftKey ? -12 : -1),
      PageDown: () => addMonths(date, event.shiftKey ? 12 : 1),
    };
    return moves[event.key]?.();
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('input[type="date"], input[type="datetime-local"]');
      if (!control) return;
      panel = document.createElement('div');
      panel.id = fruitId('fruit-calendar');
      panel.className = 'f-calendar';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', fruitMessage(root, 'label', 'Choose date'));
      panel.hidden = true;
      const header = document.createElement('div');
      header.className = 'f-calendar__header';
      title = document.createElement('div');
      title.className = 'f-calendar__title';
      title.id = `${panel.id}-title`;
      title.setAttribute('aria-live', 'polite');
      const navigation = (direction, key, fallback) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'f-calendar__nav';
        button.dataset.direction = direction;
        button.setAttribute('aria-label', fruitMessage(root, key, fallback));
        button.addEventListener('click', () => {
          focused = clamp(addMonths(focused, direction === 'next' ? 1 : -1));
          view = new Date(focused.getFullYear(), focused.getMonth(), 1);
          render();
        });
        return button;
      };
      header.append(
        title,
        navigation('previous', 'previous-label', 'Previous month'),
        navigation('next', 'next-label', 'Next month'),
      );
      grid = document.createElement('table');
      grid.className = 'f-calendar__grid';
      grid.setAttribute('role', 'grid');
      grid.setAttribute('aria-labelledby', title.id);
      grid.append(document.createElement('thead'), document.createElement('tbody'));
      panel.append(header, grid);
      // Generated UI lives in a wire:ignore mount so Livewire morphs keep it.
      (root.querySelector('[data-fruit-ui]') ?? root).append(panel);
      popup = pickerPopup(root, control, panel, {
        onOpen: () => show(clamp(selected() ?? new Date()), false),
        onFocus: () => grid.querySelector('.f-calendar__day[tabindex="0"]')?.focus(),
      });
      root.setAttribute('data-ready', '');
      // A pointer press opens the calendar in place of the browser's picker; typing stays native.
      popup.listen(control, 'click', event => {
        event.preventDefault();
        if (!popup.isOpen()) popup.open(false);
      });
      popup.listen(control, 'keydown', event => {
        if (event.altKey && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
          event.preventDefault();
          if (event.key === 'ArrowUp') popup.close(true);
          else if (popup.isOpen()) grid.querySelector('.f-calendar__day[tabindex="0"]')?.focus();
          else popup.open(true);
        } else if (event.key === 'Escape' && popup.isOpen()) {
          event.preventDefault();
          event.stopPropagation();
          popup.close(false);
        }
      });
      // Typed segments move the calendar with them.
      popup.listen(control, 'input', () => {
        const date = selected();
        if (popup.isOpen() && date) show(date, false);
      });
      popup.listen(grid, 'click', event => {
        const button = event.target.closest('.f-calendar__day');
        if (button) choose(parseDate(button.dataset.date));
      });
      popup.listen(grid, 'keydown', event => {
        const button = event.target.closest('.f-calendar__day');
        if (!button) return;
        const next = step(event, parseDate(button.dataset.date));
        if (!next) return;
        event.preventDefault();
        show(next);
      });
    },
    destroy() {
      popup?.destroy();
      panel?.remove();
      root?.removeAttribute('data-ready');
    },
  };
}

/** Apple's system colors, for raw HTML without a datalist. Blade renders them as a translated datalist. */
const SYSTEM_COLORS = [
  ['Red', '#ff3b30'],
  ['Orange', '#ff9500'],
  ['Yellow', '#ffcc00'],
  ['Green', '#34c759'],
  ['Mint', '#00c7be'],
  ['Teal', '#30b0c7'],
  ['Cyan', '#32ade6'],
  ['Blue', '#007aff'],
  ['Indigo', '#5856d6'],
  ['Purple', '#af52de'],
  ['Pink', '#ff2d55'],
  ['Brown', '#a2845e'],
  ['Gray', '#8e8e93'],
];
const COLUMNS = 7;

const hexToRgb = hex => [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255);
/** Hue (0–360), saturation and brightness (0–1) of a #rrggbb color. */
function hexToHsv(hex) {
  const [red, green, blue] = hexToRgb(hex);
  const max = Math.max(red, green, blue),
    delta = max - Math.min(red, green, blue);
  let hue = 0;
  if (delta) {
    if (max === red) hue = ((green - blue) / delta) % 6;
    else if (max === green) hue = (blue - red) / delta + 2;
    else hue = (red - green) / delta + 4;
  }
  return [(hue * 60 + 360) % 360, max ? delta / max : 0, max];
}
function hsvToHex(hue, saturation, brightness) {
  const channel = offset => {
    const k = (offset + hue / 60) % 6;
    return brightness - brightness * saturation * Math.max(0, Math.min(k, 4 - k, 1));
  };
  return `#${[channel(5), channel(3), channel(1)]
    .map(value =>
      Math.round(value * 255)
        .toString(16)
        .padStart(2, '0'),
    )
    .join('')}`;
}

/**
 * A swatch palette for a native color input. "Other…" expands a custom color editor in the same
 * popover: a saturation/brightness area, a hue slider and a hex field.
 */
export function fruitColorPicker() {
  let root, control, panel, list, other, editor, area, thumb, hue, hex, popup;
  let hsv = [0, 0, 0];
  const swatches = () => {
    const options = [...(control.list?.options ?? [])]
      .filter(option => /^#[0-9a-f]{6}$/i.test(option.value))
      .map(option => [option.label || option.value, option.value.toLowerCase()]);
    return options.length ? options : SYSTEM_COLORS;
  };
  const options = () => [...list.querySelectorAll('[role="option"]')];
  const render = () => {
    const current = control.value.toLowerCase();
    list.replaceChildren(
      ...swatches().map(([label, value]) => {
        const option = document.createElement('div');
        option.className = 'f-swatch';
        option.setAttribute('role', 'option');
        option.tabIndex = -1;
        option.title = label;
        option.dataset.value = value;
        option.style.setProperty('--f-swatch-color', value);
        option.setAttribute('aria-selected', String(value === current));
        const name = document.createElement('span');
        name.className = 'f-sr-only';
        name.textContent = label;
        option.append(name);
        return option;
      }),
    );
  };
  // The editor shows hsv; hue survives while saturation or brightness is zero.
  const paint = () => {
    const [h, saturation, brightness] = hsv;
    editor.style.setProperty('--f-picker-hue', hsvToHex(h, 1, 1));
    thumb.style.left = `${saturation * 100}%`;
    thumb.style.top = `${(1 - brightness) * 100}%`;
    const percent = value => Math.round(value * 100);
    area.setAttribute('aria-valuenow', String(percent(saturation)));
    area.setAttribute(
      'aria-valuetext',
      fruitMessage(root, 'area-text', 'Saturation {saturation}%, brightness {brightness}%', {
        saturation: percent(saturation),
        brightness: percent(brightness),
      }),
    );
    hue.value = String(Math.round(h));
    if (document.activeElement !== hex) hex.value = control.value;
  };
  const sync = () => {
    const [h, saturation, brightness] = hexToHsv(control.value);
    // Grays carry no hue: keep the hue being edited.
    hsv = [saturation && brightness ? h : hsv[0], saturation, brightness];
    paint();
  };
  const update = (next, commit) => {
    hsv = next;
    publishValue(control, hsvToHex(...hsv), { commit });
    paint();
  };
  const choose = option => {
    publishValue(control, option.dataset.value);
    popup.close(true);
  };
  const element = (tag, className, attributes = {}) => {
    const node = document.createElement(tag);
    node.className = className;
    for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
    return node;
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('input[type="color"]');
      if (!control) return;
      panel = element('div', 'f-color-palette', {
        id: fruitId('fruit-colors'),
        role: 'dialog',
        'aria-label': fruitMessage(root, 'label', 'Choose color'),
      });
      panel.hidden = true;
      list = element('div', 'f-color-palette__swatches', {
        role: 'listbox',
        'aria-label': fruitMessage(root, 'colors-label', 'Colors'),
      });
      list.style.setProperty('--f-swatch-columns', String(COLUMNS));
      other = element('button', 'f-button f-button--ghost f-button--small f-color-palette__other', {
        type: 'button',
        'aria-expanded': 'false',
      });
      other.textContent = fruitMessage(root, 'other-label', 'Other…');
      editor = element('div', 'f-color-editor');
      editor.hidden = true;
      editor.style.setProperty('--f-picker-black', '#000');
      editor.style.setProperty('--f-picker-white', '#fff');
      editor.style.setProperty(
        '--f-picker-spectrum',
        'linear-gradient(90deg, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00)',
      );
      area = element('div', 'f-color-editor__area', {
        role: 'slider',
        tabindex: '0',
        'aria-label': fruitMessage(root, 'area-label', 'Saturation and brightness'),
        'aria-valuemin': '0',
        'aria-valuemax': '100',
      });
      thumb = element('span', 'f-color-editor__thumb', { 'aria-hidden': 'true' });
      area.append(thumb);
      hue = element('input', 'f-range f-color-editor__hue', {
        type: 'range',
        min: '0',
        max: '359',
        'aria-label': fruitMessage(root, 'hue-label', 'Hue'),
      });
      const hexLabel = element('label', 'f-color-editor__hex');
      const hexName = element('span', 'f-label');
      hexName.textContent = fruitMessage(root, 'hex-label', 'Hex');
      hex = element('input', 'f-input', { type: 'text', maxlength: '7', spellcheck: 'false', autocomplete: 'off' });
      hexLabel.append(hexName, hex);
      editor.append(area, hue, hexLabel);
      other.setAttribute('aria-controls', (editor.id = `${panel.id}-editor`));
      panel.append(list, other, editor);
      render();
      (root.querySelector('[data-fruit-ui]') ?? root).append(panel);
      popup = pickerPopup(root, control, panel, {
        onOpen: () => {
          render();
          editor.hidden = true;
          other.hidden = false;
          other.setAttribute('aria-expanded', 'false');
        },
        onFocus: () => {
          const all = options();
          (all.find(option => option.getAttribute('aria-selected') === 'true') ?? all[0])?.focus();
        },
      });
      root.setAttribute('data-ready', '');
      popup.listen(control, 'click', event => {
        event.preventDefault();
        if (popup.isOpen()) popup.close(false);
        else popup.open(true);
      });
      popup.listen(control, 'input', () => {
        if (!editor.hidden) sync();
      });
      popup.listen(list, 'click', event => {
        const option = event.target.closest('[role="option"]');
        if (option) choose(option);
      });
      popup.listen(list, 'keydown', event => {
        const all = options(),
          index = all.indexOf(document.activeElement);
        if (index < 0) return;
        const forward = isRtl(control) ? -1 : 1;
        const moves = {
          ArrowRight: index + forward,
          ArrowLeft: index - forward,
          ArrowDown: index + COLUMNS,
          ArrowUp: index - COLUMNS,
          Home: 0,
          End: all.length - 1,
        };
        if (event.key in moves) {
          event.preventDefault();
          all[Math.max(0, Math.min(all.length - 1, moves[event.key]))].focus();
        } else if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          choose(all[index]);
        }
      });
      // Other… expands the custom editor in place, on the current color.
      popup.listen(other, 'click', () => {
        editor.hidden = false;
        other.hidden = true;
        other.setAttribute('aria-expanded', 'true');
        sync();
        area.focus();
      });
      const pick = (event, commit) => {
        const rect = area.getBoundingClientRect();
        const clamp = value => Math.max(0, Math.min(1, value));
        update(
          [
            hsv[0],
            clamp((event.clientX - rect.left) / rect.width),
            1 - clamp((event.clientY - rect.top) / rect.height),
          ],
          commit,
        );
      };
      let dragging = false;
      popup.listen(area, 'pointerdown', event => {
        if (event.button !== 0) return;
        event.preventDefault();
        area.focus();
        area.setPointerCapture(event.pointerId);
        dragging = true;
        pick(event, false);
      });
      popup.listen(area, 'pointermove', event => {
        if (dragging) pick(event, false);
      });
      popup.listen(area, 'pointerup', event => {
        if (!dragging) return;
        dragging = false;
        pick(event, true);
      });
      popup.listen(area, 'keydown', event => {
        const step = event.shiftKey ? 0.1 : 0.01;
        const moves = {
          ArrowLeft: [-step, 0],
          ArrowRight: [step, 0],
          ArrowDown: [0, -step],
          ArrowUp: [0, step],
        };
        const move = moves[event.key];
        if (!move) return;
        event.preventDefault();
        const clamp = value => Math.max(0, Math.min(1, value));
        update([hsv[0], clamp(hsv[1] + move[0]), clamp(hsv[2] + move[1])], true);
      });
      popup.listen(hue, 'input', () => update([Number(hue.value), hsv[1], hsv[2]], false));
      popup.listen(hue, 'change', () => update([Number(hue.value), hsv[1], hsv[2]], true));
      popup.listen(hex, 'input', () => {
        const value = hex.value.trim().toLowerCase();
        const full = /^#?[0-9a-f]{6}$/.test(value) ? `#${value.replace('#', '')}` : null;
        if (!full) return;
        publishValue(control, full);
        sync();
      });
      popup.listen(hex, 'blur', () => (hex.value = control.value));
      popup.listen(hex, 'keydown', event => {
        if (event.key === 'Enter') {
          event.preventDefault();
          popup.close(true);
        }
      });
    },
    destroy() {
      popup?.destroy();
      panel?.remove();
      root?.removeAttribute('data-ready');
    },
  };
}

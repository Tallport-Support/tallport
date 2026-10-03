import { fruitId } from './control-bridge.js';
import { fruitDetailsPopup, fruitPopup, isRtl } from './popup.js';

const menuItems = '[role="menuitem"], [role="menuitemcheckbox"], [role="menuitemradio"]';
const enabledItems = (menu, owns) =>
  [...menu.querySelectorAll(menuItems)].filter(
    item =>
      owns(item) &&
      !item.matches(':disabled') &&
      item.getAttribute('aria-disabled') !== 'true' &&
      item.getClientRects().length,
  );
const focusItem = (enabled, index) => {
  if (enabled.length) enabled[(index + enabled.length) % enabled.length].focus();
};
/** The visible label of a menu item, without its shortcut. */
const itemLabel = item =>
  [...item.childNodes]
    .filter(node => !node.classList?.contains('f-menu-item__shortcut'))
    .map(node => node.textContent)
    .join('')
    .trim()
    .toLocaleLowerCase();

/** Arrows, Home/End and typeahead within an open menu. Returns whether the key was handled. */
function navigateMenu(event, enabled, typeahead) {
  const index = enabled.indexOf(document.activeElement);
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
    event.preventDefault();
    focusItem(
      enabled,
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? enabled.length - 1
          : index + (event.key === 'ArrowDown' ? 1 : -1),
    );
    return true;
  }
  if (event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
    event.preventDefault();
    clearTimeout(typeahead.timer);
    typeahead.search += event.key.toLocaleLowerCase();
    const ordered = [...enabled.slice(index + 1), ...enabled.slice(0, index + 1)];
    ordered.find(item => itemLabel(item).startsWith(typeahead.search))?.focus();
    typeahead.timer = setTimeout(() => {
      typeahead.search = '';
    }, 600);
    return true;
  }
  return false;
}

export function fruitMenu() {
  let details, trigger, popup, floating, keydown, click, timer;
  const typeahead = { search: '', timer: null };
  const owns = node => node?.closest('[data-fruit-menu], [x-data^="fruitMenu"]') === details;
  const items = () => enabledItems(popup, owns);
  const focus = index => focusItem(items(), index);
  return {
    init() {
      details = this.$el;
      details.setAttribute('data-fruit-menu', '');
      trigger = details.querySelector('summary');
      popup = details.querySelector('[role="menu"]');
      if (!trigger || !popup) return;
      floating = fruitDetailsPopup(details, popup, {
        above: details.classList.contains('f-menu--above'),
        owns,
        onToggle: open => {
          trigger.setAttribute('aria-expanded', String(open));
          if (open && document.activeElement === trigger) focus(0);
        },
      });
      popup.id ||= fruitId('fruit-menu');
      trigger.setAttribute('aria-haspopup', 'menu');
      trigger.setAttribute('aria-controls', popup.id);
      trigger.setAttribute('aria-expanded', String(details.open));
      popup.querySelectorAll(menuItems).forEach(item => {
        if (owns(item)) item.tabIndex = -1;
      });
      keydown = event => {
        if (!owns(event.target)) return;
        const enabled = items();
        if (event.target === trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
          event.preventDefault();
          details.open = true;
          floating.show();
          focus(event.key === 'ArrowDown' ? 0 : enabled.length - 1);
        } else if (details.open && event.key === 'Tab') {
          timer = setTimeout(() => floating.close(false), 0);
        } else if (details.open) navigateMenu(event, enabled, typeahead);
      };
      click = event => {
        const item = event.target.closest(menuItems);
        if (owns(item) && !item.matches(':disabled') && item.getAttribute('aria-disabled') !== 'true')
          floating.close(true);
      };
      details.addEventListener('keydown', keydown);
      popup.addEventListener('click', click);
    },
    destroy() {
      clearTimeout(timer);
      clearTimeout(typeahead.timer);
      floating?.destroy();
      details?.removeEventListener('keydown', keydown);
      popup?.removeEventListener('click', click);
    },
  };
}

/**
 * A menu of commands for its parent element, opened by a secondary click, Shift+F10 or the
 * context-menu key. It appears at the pointer (or below the focused control), and closing it
 * returns focus to where it was. Offer the same commands elsewhere: context menus are hidden.
 */
export function fruitContextMenu() {
  let menu, target, overlay, controller, origin, timer;
  let point = { x: 0, y: 0 };
  const typeahead = { search: '', timer: null };
  const owns = node => node?.closest('[data-fruit-menu]') === menu;
  const isOpen = () => !menu.hidden;
  const close = restoreFocus => {
    if (!isOpen()) return;
    overlay.hide();
    menu.hidden = true;
    target.removeAttribute('data-fruit-context-open');
    if (restoreFocus && origin?.isConnected) origin.focus();
  };
  const open = (x, y, from) => {
    origin = from;
    point = { x, y };
    menu.hidden = false;
    target.setAttribute('data-fruit-context-open', '');
    overlay.show();
    focusItem(enabledItems(menu, owns), 0);
  };
  return {
    init() {
      menu = this.$el;
      target = menu.parentElement;
      if (!target) return;
      menu.setAttribute('data-fruit-menu', '');
      menu.id ||= fruitId('fruit-context-menu');
      menu.hidden = true;
      menu.querySelectorAll(menuItems).forEach(item => {
        if (owns(item)) item.tabIndex = -1;
      });
      const anchor = { getBoundingClientRect: () => new DOMRect(point.x, point.y, 0, 0) };
      overlay = fruitPopup(menu, anchor, { point: target });
      controller = new AbortController();
      const listen = (node, event, handler) => node.addEventListener(event, handler, { signal: controller.signal });
      let fromKeyboard = false;
      listen(target, 'contextmenu', event => {
        if (menu.contains(event.target)) return;
        event.preventDefault();
        if (fromKeyboard) return;
        open(event.clientX, event.clientY, document.activeElement);
      });
      listen(target, 'keydown', event => {
        if (menu.contains(event.target) || !((event.key === 'F10' && event.shiftKey) || event.key === 'ContextMenu'))
          return;
        event.preventDefault();
        // The browser may also fire contextmenu for this key; open once, below the focused control.
        fromKeyboard = true;
        timer = setTimeout(() => (fromKeyboard = false), 0);
        const rect = event.target.getBoundingClientRect();
        open(isRtl(target) ? rect.right : rect.left, rect.bottom, event.target);
      });
      listen(menu, 'keydown', event => {
        if (!owns(event.target)) return;
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          close(true);
        } else if (event.key === 'Tab') {
          event.preventDefault();
          close(true);
        } else navigateMenu(event, enabledItems(menu, owns), typeahead);
      });
      listen(menu, 'click', event => {
        const item = event.target.closest(menuItems);
        if (owns(item) && !item.matches(':disabled') && item.getAttribute('aria-disabled') !== 'true') close(true);
      });
      listen(document, 'pointerdown', event => {
        if (isOpen() && !menu.contains(event.target)) close(false);
      });
      listen(window, 'blur', () => close(false));
    },
    destroy() {
      clearTimeout(timer);
      clearTimeout(typeahead.timer);
      controller?.abort();
      overlay?.destroy();
      target?.removeAttribute('data-fruit-context-open');
    },
  };
}

export function fruitTooltip() {
  let root, keydown, leave, enter, focus, overlay, timer;
  return {
    init() {
      root = this.$el;
      const text = root.querySelector('[role="tooltip"]');
      if (text) overlay = fruitPopup(text, root.firstElementChild);
      // Like help tags, hover waits before showing; keyboard focus shows the text at once.
      const show = () => {
        if (!root.hasAttribute('data-dismissed')) overlay?.show();
      };
      enter = () => {
        clearTimeout(timer);
        timer = setTimeout(show, 600);
      };
      focus = () => {
        clearTimeout(timer);
        show();
      };
      keydown = event => {
        if (event.key === 'Escape' && (root.matches(':hover') || root.contains(document.activeElement))) {
          root.setAttribute('data-dismissed', '');
          overlay?.hide();
          event.stopPropagation();
        }
      };
      leave = event => {
        if (!root.contains(event.relatedTarget)) {
          clearTimeout(timer);
          root.removeAttribute('data-dismissed');
          if (!root.matches(':hover') && !root.contains(document.activeElement)) overlay?.hide();
        }
      };
      document.addEventListener('keydown', keydown);
      root.addEventListener('mouseleave', leave);
      root.addEventListener('focusout', leave);
      root.addEventListener('mouseenter', enter);
      root.addEventListener('focusin', focus);
    },
    destroy() {
      clearTimeout(timer);
      overlay?.destroy();
      document.removeEventListener('keydown', keydown);
      root.removeEventListener('mouseleave', leave);
      root.removeEventListener('focusout', leave);
      root.removeEventListener('mouseenter', enter);
      root.removeEventListener('focusin', focus);
    },
  };
}

/** In-page tabs only; navigation links retain ordinary link semantics. */
export function fruitTabs() {
  let root, keydown, click, observer;
  const owns = node => node?.closest('[data-fruit-tabs], [x-data^="fruitTabs"]') === root;
  const all = () => [...root.querySelectorAll('[role="tab"]')].filter(owns);
  const tabs = () => all().filter(tab => !tab.matches(':disabled') && tab.getAttribute('aria-disabled') !== 'true');
  const activate = tab => {
    for (const item of all()) {
      const selected = item === tab;
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
      const panel = [...root.querySelectorAll('[role="tabpanel"]')].find(
        panel => owns(panel) && panel.id === item.getAttribute('aria-controls'),
      );
      if (panel) panel.hidden = !selected;
    }
  };
  const reconcile = () => activate(tabs().find(tab => tab.getAttribute('aria-selected') === 'true') || tabs()[0]);
  return {
    init() {
      root = this.$el;
      root.setAttribute('data-fruit-tabs', '');
      reconcile();
      click = event => {
        const tab = event.target.closest('[role="tab"]');
        if (tabs().includes(tab)) activate(tab);
      };
      keydown = event => {
        const enabled = tabs(),
          index = enabled.indexOf(event.target);
        if (index < 0 || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        event.stopPropagation();
        const rtl = isRtl(root);
        const next =
          event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? enabled.length - 1
              : (index + ((event.key === 'ArrowRight') !== rtl ? 1 : -1) + enabled.length) % enabled.length;
        activate(enabled[next]);
        enabled[next].focus();
      };
      root.addEventListener('click', click);
      root.addEventListener('keydown', keydown);
      observer = new MutationObserver(reconcile);
      observer.observe(root, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['disabled', 'aria-disabled'],
      });
    },
    destroy() {
      observer.disconnect();
      root.removeEventListener('click', click);
      root.removeEventListener('keydown', keydown);
    },
  };
}

/** Native disclosure controls keep their own semantics; reuse popup placement only. */
export function fruitFloatingDisclosure() {
  let floating;
  return {
    init() {
      const details = this.$el;
      const content = details.querySelector('.f-floating-disclosure__content');
      if (!details.querySelector('summary') || !content) return;
      floating = fruitDetailsPopup(details, content, {
        above: details.classList.contains('f-floating-disclosure--above'),
        owns: node => node?.closest('.f-floating-disclosure') === details,
      });
    },
    close(restoreFocus = false) {
      floating?.close(restoreFocus);
    },
    destroy() {
      floating?.destroy();
    },
  };
}

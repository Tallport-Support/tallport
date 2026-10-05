import { fruitId } from './control-bridge.js';

const SIZES = ['medium', 'large'];

/** Translated labels from the layout's <x-fruit::remote-dialog />, or English. */
const label = (key, fallback) =>
  document.querySelector('template[data-fruit-remote-dialog]')?.getAttribute(`data-fruit-${key}`) ?? fallback;

function frame(title, size) {
  const id = fruitId('fruit-dialog');
  const element = document.createElement('dialog');
  element.className = `f-dialog f-dialog--scroll${size === 'large' ? ' f-dialog--large' : ''}`;
  // Outside a .fruit-ui scope (the compat build), the dialog carries its own.
  if (!document.body.closest('.fruit-ui')) element.classList.add('fruit-ui');
  element.setAttribute('aria-labelledby', `${id}-title`);
  element.innerHTML = `<header class="f-dialog__header"><h2 id="${id}-title"></h2><button class="f-button f-button--ghost f-button--icon" type="button" data-fruit-dialog-close><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button></header><div class="f-dialog__body"></div>`;
  element.querySelector('h2').textContent = title;
  element.querySelector('[data-fruit-dialog-close]').setAttribute('aria-label', label('close-label', 'Close'));
  return element;
}

function loading() {
  const status = document.createElement('p');
  status.className = 'f-sr-only';
  status.setAttribute('role', 'status');
  status.textContent = label('loading-label', 'Loading…');
  const skeleton = document.createElement('div');
  skeleton.className = 'f-skeleton';
  skeleton.setAttribute('aria-hidden', 'true');
  skeleton.innerHTML = '<div class="f-skeleton__line"></div>'.repeat(4);
  return [status, skeleton];
}

/**
 * Open a modal dialog with content the application supplies: `html` (a string or a node) or a
 * `url` to load it from, shown with a skeleton meanwhile. Alpine starts on the inserted content
 * by itself. A trailing `.f-dialog__footer` in the content becomes the dialog's footer, and
 * `<form method="dialog">` buttons close it with their value. The dialog is removed on close.
 *
 * Returns `{ element, body, loaded, closed, close(value) }`: `loaded` resolves with the body once
 * the content is in (a bubbling `fruit-dialog-loaded` event says the same), `closed` with the
 * dialog's return value.
 */
export function dialog({ title, html, url, size = 'medium', trigger = null } = {}) {
  if (typeof title !== 'string' || !title.trim()) throw new Error('FruitUI dialog needs a title.');
  if ((html === undefined) === (url === undefined)) throw new Error('FruitUI dialog needs either html or url.');
  if (!SIZES.includes(size)) throw new Error(`FruitUI dialog size must be one of: ${SIZES.join(', ')}.`);
  const element = frame(title, size);
  const body = element.querySelector('.f-dialog__body');
  const opener = document.activeElement;
  const controller = new AbortController();
  let resolveLoaded, rejectLoaded, resolveClosed;
  const loaded = new Promise((resolve, reject) => ((resolveLoaded = resolve), (rejectLoaded = reject)));
  const closed = new Promise(resolve => (resolveClosed = resolve));
  loaded.catch(() => {});

  const fill = content => {
    if (typeof content === 'string') body.innerHTML = content;
    else body.replaceChildren(content);
    const footer = body.lastElementChild;
    if (footer?.matches('.f-dialog__footer')) element.append(footer);
    body.removeAttribute('aria-busy');
    resolveLoaded(body);
    element.dispatchEvent(
      new CustomEvent('fruit-dialog-loaded', { bubbles: true, detail: { dialog: element, body, url, trigger } }),
    );
  };
  const load = async () => {
    body.setAttribute('aria-busy', 'true');
    body.replaceChildren(...loading());
    try {
      const response = await fetch(url, {
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`FruitUI dialog could not load ${url}: ${response.status}.`);
      const text = await response.text();
      if (element.isConnected) fill(text);
    } catch (error) {
      if (controller.signal.aborted) return;
      body.removeAttribute('aria-busy');
      const message = document.createElement('p');
      message.className = 'f-error';
      message.setAttribute('role', 'alert');
      message.textContent = label('error-message', 'Could not load this content.');
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'f-button';
      retry.textContent = label('retry-label', 'Try Again');
      retry.addEventListener('click', load);
      const actions = document.createElement('div');
      actions.append(retry);
      body.replaceChildren(message, actions);
      element.dispatchEvent(
        new CustomEvent('fruit-dialog-error', { bubbles: true, detail: { dialog: element, url, error } }),
      );
    }
  };

  element.querySelector('[data-fruit-dialog-close]').addEventListener('click', () => element.close(''));
  element.addEventListener('close', () => {
    controller.abort();
    rejectLoaded(new Error('FruitUI dialog closed before its content loaded.'));
    element.remove();
    resolveClosed(element.returnValue);
    if (opener?.isConnected && typeof opener.focus === 'function') opener.focus();
  });
  document.body.append(element);
  element.showModal();
  if (url === undefined) fill(html);
  else load();
  return { element, body, loaded, closed, close: value => element.close(value ?? '') };
}

/** Links and buttons with data-fruit-dialog-url (or the link's href) open their content in a dialog. */
export function listenForRemoteDialogs(target = document) {
  const open = event => {
    const trigger = event.target.closest?.('[data-fruit-dialog-url]');
    // Modifier clicks keep a link's own behavior, such as opening a new tab.
    if (!trigger || event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    // Blade renders a valueless attribute on a component as its own name; both mean "use href".
    const named = trigger.dataset.fruitDialogUrl;
    const url = (named && named !== 'data-fruit-dialog-url' ? named : '') || trigger.getAttribute('href');
    if (!url) return;
    event.preventDefault();
    dialog({
      url,
      title: trigger.dataset.fruitDialogTitle || trigger.textContent.trim(),
      size: trigger.dataset.fruitDialogSize || 'medium',
      trigger,
    });
  };
  target.addEventListener('click', open);
  return () => target.removeEventListener('click', open);
}

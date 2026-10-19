/*
 * Tallport's website chat, the website's part: a launcher button and the chat window's frame. The
 * window itself (w beside this file, WebChatController) is a FruitUI page; this script only places it,
 * so it carries no framework and needs nothing from the website. From FruitUI's Widget example
 * (public/widget/t.js). A website adds it with a snippet
 * (Mailbox » Website Chat shows its own):
 *
 *   <script>
 *     window.tallport = window.tallport || function () { (tallport.q = tallport.q || []).push(arguments); };
 *     tallport('init', { widget: 'forma-support' });
 *   </script>
 *   <script src="https://help.example.com/t/t.js" async></script>
 *
 * The window loads from the folder this script came from, so a website can serve both through its
 * own domain (a proxy for the folder) and the script finds the window there. Commands, queued until
 * the script loads: init, open, close, identify (a token the website's server signs), logout and
 * appearance ('system', 'light' or 'dark'). The launcher appears once the window says it is ready,
 * so a window that can't load (or isn't allowed on this website) leaves no button behind. The styles
 * are adopted rather than inline, so a strict Content Security Policy needs only script-src and
 * frame-src for the script's domain. It has no words of its own: the window sends the launcher's
 * name, so it is in the visitor's language. Icons: Lucide (https://lucide.dev, ISC licence).
 */
(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || window.tallport?.loaded) return;
  const base = new URL('./', script.src);
  const queued = window.tallport?.q ?? [];
  const icons = {
    // message-circle
    chat: '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/>',
    // chevron-down
    down: '<path d="m6 9 6 6 6-6"/>',
  };
  const css = `
    .root { all: initial; }
    .launcher {
      position: fixed;
      inset: auto 20px 20px auto;
      z-index: 2147483000;
      display: grid;
      place-items: center;
      width: 56px;
      height: 56px;
      padding: 0;
      border: 0;
      border-radius: 50%;
      background: var(--fill);
      color: #fff;
      box-shadow: 0 6px 16px rgb(0 0 0 / 16%), 0 1px 3px rgb(0 0 0 / 12%);
      cursor: pointer;
      transition: transform 0.15s, background-color 0.15s;
    }
    .launcher[hidden] { display: none; }
    .launcher:hover { background: var(--fill-hover); }
    .launcher:active { transform: scale(0.96); }
    .launcher:focus-visible { outline: 3px solid var(--fill); outline-offset: 3px; }
    .launcher svg {
      grid-area: 1 / 1;
      width: 26px;
      height: 26px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      transition: opacity 0.15s, transform 0.15s;
    }
    .launcher .open { opacity: 0; transform: rotate(-90deg); }
    .root[data-open] .launcher .closed { opacity: 0; transform: rotate(90deg); }
    .root[data-open] .launcher .open { opacity: 1; transform: none; }
    .label {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }
    .badge {
      position: absolute;
      inset: -3px -3px auto auto;
      min-width: 20px;
      height: 20px;
      box-sizing: border-box;
      padding: 0 6px;
      border-radius: 10px;
      background: #d70015;
      box-shadow: 0 0 0 2px #fff;
      color: #fff;
      font: 600 12px/20px system-ui, sans-serif;
      text-align: center;
    }
    .badge[hidden] { display: none; }
    .window {
      position: fixed;
      inset: auto 20px 88px auto;
      z-index: 2147483000;
      width: min(380px, 100vw - 40px);
      height: min(640px, 100dvh - 108px);
      overflow: hidden;
      border-radius: 16px;
      background: var(--canvas, #fff);
      box-shadow: 0 0 0 1px var(--edge, rgb(0 0 0 / 8%)), 0 16px 48px rgb(0 0 0 / 18%);
      opacity: 0;
      transform: translateY(8px) scale(0.98);
      transform-origin: bottom right;
      visibility: hidden;
      transition: opacity 0.16s, transform 0.16s, visibility 0s 0.16s;
    }
    .root[data-open] .window {
      opacity: 1;
      transform: none;
      visibility: visible;
      transition: opacity 0.16s, transform 0.16s;
    }
    iframe { display: block; width: 100%; height: 100%; border: 0; }
    /* A phone gives the chat the whole screen; the window's own Close button returns. */
    @media (max-width: 480px) {
      .window { inset: 0; width: auto; height: auto; border-radius: 0; box-shadow: none; }
      .root[data-open] .launcher { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
      .launcher, .launcher svg, .window, .root[data-open] .window { transition: none; }
    }
    @media (forced-colors: active) {
      .launcher { border: 2px solid ButtonText; }
      .window { border: 1px solid CanvasText; }
    }
    @media print {
      .root { display: none; }
    }`;

  let root, launcher, label, badge, frame, config, name;
  let ready = false;
  let opened = false;
  let openWhenReady = false;
  let unread = 0;
  const outbox = [];

  const post = (type, data = {}) => {
    if (ready) frame.contentWindow.postMessage({ tallport: 1, type, ...data }, base.origin);
    else outbox.push([type, data]);
  };

  const icon = (name, className) =>
    `<svg class="${className}" viewBox="0 0 24 24" aria-hidden="true">${icons[name]}</svg>`;

  function start(options = {}) {
    if (root) return;
    config = { widget: script.dataset.widget, ...options };
    const host = document.createElement('div');
    const shadow = host.attachShadow({ mode: 'open' });
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(css);
    shadow.adoptedStyleSheets = [sheet];
    shadow.innerHTML = `<div class="root"><button class="launcher" type="button" aria-expanded="false" aria-controls="window" hidden>${icon('chat', 'closed')}${icon('down', 'open')}<span class="label"></span><span class="badge" aria-hidden="true" hidden></span></button><div class="window" id="window"><iframe></iframe></div></div>`;
    root = shadow.querySelector('.root');
    launcher = shadow.querySelector('.launcher');
    label = shadow.querySelector('.label');
    badge = shadow.querySelector('.badge');
    frame = shadow.querySelector('iframe');
    const url = new URL('w', base);
    url.searchParams.set('widget', config.widget ?? '');
    url.searchParams.set('origin', location.origin);
    // The page the visitor is on, for the team (without its fragment).
    url.searchParams.set('page', location.href.replace(/#.*$/, ''));
    frame.src = url;
    launcher.addEventListener('click', () => (opened ? close() : open({ focus: true })));
    root.addEventListener('keydown', event => {
      if (event.key === 'Escape' && opened) close({ focus: true });
    });
    // A script in the head can run before the body exists.
    if (document.body) document.body.append(host);
    else addEventListener('DOMContentLoaded', () => document.body.append(host), { once: true });
  }

  // The count shows on the badge, and the launcher's name says it in the window's words ("Chat with
  // Forma, 2 unread"): every word comes from the window, in the visitor's language.
  function setUnread(count, words) {
    unread = opened ? 0 : count;
    badge.hidden = unread === 0;
    badge.textContent = unread || '';
    label.textContent = unread && words ? words : name;
  }

  function theme({ fill, fillHover, canvas, dark }) {
    root.style.setProperty('--fill', fill);
    root.style.setProperty('--fill-hover', fillHover);
    root.style.setProperty('--canvas', canvas);
    root.style.setProperty('--edge', dark ? 'rgb(255 255 255 / 14%)' : 'rgb(0 0 0 / 8%)');
  }

  // Focus moves into the window when the visitor opened it (the launcher, or the website's own button
  // or link calling open), never when the website opens it by itself, such as on page load.
  function open({ focus = navigator.userActivation?.isActive ?? true } = {}) {
    if (!ready) return void (openWhenReady = true);
    if (opened) return;
    opened = true;
    root.toggleAttribute('data-open', true);
    launcher.setAttribute('aria-expanded', 'true');
    setUnread(0);
    post('open', { focus });
    if (focus) frame.focus();
  }

  function close({ focus = false } = {}) {
    openWhenReady = false;
    if (!opened) return;
    opened = false;
    root.toggleAttribute('data-open', false);
    launcher.setAttribute('aria-expanded', 'false');
    post('closed');
    if (focus) launcher.focus();
  }

  addEventListener('message', event => {
    if (!frame || event.source !== frame.contentWindow || event.origin !== base.origin) return;
    const data = event.data;
    if (data?.tallport !== 1) return;
    if (data.type === 'ready') {
      ready = true;
      name = data.label;
      label.textContent = name;
      frame.title = name;
      theme(data);
      launcher.hidden = false;
      for (const [type, payload] of outbox.splice(0)) post(type, payload);
      if (openWhenReady) open({ focus: false });
    } else if (data.type === 'theme') theme(data);
    else if (data.type === 'unread') setUnread(data.count, data.label);
    else if (data.type === 'close') close({ focus: true });
  });

  const commands = {
    init: start,
    open: () => open(),
    close: () => close(),
    identify: token => post('identify', { token }),
    logout: () => post('logout'),
    appearance: value => post('appearance', { value }),
  };
  // Commands before init wait for it; init runs first, whatever the order they were queued in.
  const pending = [];
  const run = (command, ...args) => {
    if (command === 'init') {
      start(...args);
      for (const [waiting, ...rest] of pending.splice(0)) commands[waiting]?.(...rest);
    } else if (!root) pending.push([command, ...args]);
    else commands[command]?.(...args);
  };
  window.tallport = Object.assign(run, { loaded: true });
  const init = queued.find(([command]) => command === 'init');
  if (init) run(...init);
  else if (script.dataset.widget) run('init');
  for (const [command, ...args] of queued) if (command !== 'init') run(command, ...args);
})();

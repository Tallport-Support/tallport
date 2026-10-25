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
 * so a window that can't load (or isn't allowed on this website) leaves no button behind. A window
 * that is ready but takes no new chats now (a limit reached, for a visitor without a conversation)
 * says available: false: no button, open does nothing, and window.tallport.available stays false, so
 * a website's own "Chat with us" link can follow its href to the window's page instead. The styles
 * are adopted rather than inline, so a strict Content Security Policy needs only script-src and
 * frame-src for the script's domain. It has no words of its own: the window sends the launcher's
 * name, so it is in the visitor's language. Icons: Lucide (https://lucide.dev, ISC licence).
 *
 * An answer that arrives while the window is closed counts on the launcher's badge and shows its start
 * in a card beside it, which opens the chat (or is dismissed, the badge staying); while the website's
 * tab is in the background, the tab's title alternates with the answer's news until the visitor
 * returns. The window chimes too, unless the visitor muted it in its header. init's options: title:
 * false leaves the website's title alone; sound: false starts the chime off (the visitor may turn it
 * on, and their choice is kept in their browser).
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
    // x
    close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
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
    .label, .announce {
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
    /* An answer's start, beside the launcher while the window is closed: who wrote, then two lines. */
    .preview {
      position: fixed;
      inset: auto 20px 88px auto;
      z-index: 2147483000;
      display: flex;
      align-items: flex-start;
      gap: 2px;
      width: min(300px, 100vw - 40px);
      box-sizing: border-box;
      padding: 4px;
      border-radius: 14px;
      background: var(--canvas, #fff);
      color: var(--text, #1d1d1f);
      box-shadow: 0 0 0 1px var(--edge, rgb(0 0 0 / 8%)), 0 10px 30px rgb(0 0 0 / 16%);
      font: 14px/1.4 system-ui, sans-serif;
      animation: preview-in 0.18s ease-out;
    }
    .preview[hidden] { display: none; }
    .preview button {
      border: 0;
      background: none;
      color: inherit;
      font: inherit;
      cursor: pointer;
    }
    .preview button:focus-visible { outline: 2px solid var(--fill); outline-offset: 1px; }
    .preview-open {
      display: flex;
      flex: 1;
      align-items: flex-start;
      gap: 10px;
      min-width: 0;
      padding: 8px;
      border-radius: 10px;
      text-align: start;
    }
    .preview-open:hover, .preview-dismiss:hover { background: color-mix(in srgb, currentColor 8%, transparent); }
    .avatar {
      display: grid;
      flex: none;
      place-items: center;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--fill);
      color: #fff;
      font: 600 12px/1 system-ui, sans-serif;
    }
    .preview-body { display: grid; gap: 1px; min-width: 0; }
    .preview-author { font-weight: 600; }
    .preview-text {
      display: -webkit-box;
      overflow: hidden;
      color: var(--secondary, #6e6e73);
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      line-clamp: 2;
    }
    .preview .preview-dismiss {
      display: grid;
      flex: none;
      place-items: center;
      width: 28px;
      height: 28px;
      padding: 0;
      border-radius: 50%;
      color: var(--secondary, #6e6e73);
    }
    .preview-dismiss svg {
      width: 16px;
      height: 16px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    @keyframes preview-in { from { opacity: 0; transform: translateY(6px); } }
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
      .preview { animation: none; }
    }
    @media (forced-colors: active) {
      .launcher { border: 2px solid ButtonText; }
      .window, .preview { border: 1px solid CanvasText; }
    }
    @media print {
      .root { display: none; }
    }`;

  let root, launcher, label, badge, frame, config, name, preview, announce;
  // The tab's title while it alternates with an answer's news: the website's own, and the timer.
  let ownTitle = '';
  let titleTimer = null;
  let ready = false;
  let available = false;
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
    shadow.innerHTML = `<div class="root"><div class="preview" hidden><button class="preview-open" type="button"><span class="avatar" aria-hidden="true"></span><span class="preview-body"><span class="preview-author"></span><span class="preview-text"></span></span></button><button class="preview-dismiss" type="button">${icon('close', '')}</button></div><button class="launcher" type="button" aria-expanded="false" aria-controls="window" hidden>${icon('chat', 'closed')}${icon('down', 'open')}<span class="label"></span><span class="badge" aria-hidden="true" hidden></span></button><span class="announce" role="status"></span><div class="window" id="window"><iframe></iframe></div></div>`;
    root = shadow.querySelector('.root');
    preview = shadow.querySelector('.preview');
    announce = shadow.querySelector('.announce');
    launcher = shadow.querySelector('.launcher');
    label = shadow.querySelector('.label');
    badge = shadow.querySelector('.badge');
    frame = shadow.querySelector('iframe');
    const url = new URL('w', base);
    url.searchParams.set('widget', config.widget ?? '');
    url.searchParams.set('origin', location.origin);
    // The page the visitor is on, for the team (without its fragment).
    url.searchParams.set('page', location.href.replace(/#.*$/, ''));
    if (config.sound === false) url.searchParams.set('sound', 'off');
    frame.src = url;
    launcher.addEventListener('click', () => (opened ? close() : open({ focus: true })));
    preview.querySelector('.preview-open').addEventListener('click', () => open({ focus: true }));
    preview.querySelector('.preview-dismiss').addEventListener('click', () => {
      preview.hidden = true;
      launcher.focus();
    });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) stopTitle();
    });
    root.addEventListener('keydown', event => {
      if (event.key === 'Escape' && opened) close({ focus: true });
    });
    // A script in the head can run before the body exists.
    if (document.body) document.body.append(host);
    else addEventListener('DOMContentLoaded', () => document.body.append(host), { once: true });
  }

  // The count shows on the badge, and the launcher's name says it in the window's words ("Chat with
  // Forma, 2 unread"): every word comes from the window, in the visitor's language.
  function setUnread(count, words, news) {
    unread = opened ? 0 : count;
    badge.hidden = unread === 0;
    badge.textContent = unread || '';
    label.textContent = unread && words ? words : name;
    if (!unread) {
      preview.hidden = true;
      stopTitle();
      return;
    }
    if (news?.preview) showPreview(news.preview);
    if (news?.title) flashTitle(`(${unread}) ${news.title}`);
  }

  // The answer's start, in the window's words; a new answer shows again what was dismissed.
  function showPreview({ author, initials, text, dismiss }) {
    preview.querySelector('.avatar').textContent = initials ?? '';
    preview.querySelector('.preview-author').textContent = author;
    preview.querySelector('.preview-text').textContent = text;
    preview.querySelector('.preview-dismiss').setAttribute('aria-label', dismiss);
    preview.querySelector('.preview-dismiss').title = dismiss;
    preview.hidden = false;
    announce.textContent = `${author}: ${text}`;
  }

  // Only while the tab is in the background, and never on a website that turned it off: on a page in
  // view the badge and the card say it, and a changing title would only distract.
  function flashTitle(news) {
    if (config.title === false || !document.hidden) return;
    if (!titleTimer) ownTitle = document.title;
    let showing = true;
    document.title = news;
    clearInterval(titleTimer);
    titleTimer = setInterval(() => {
      // The website changed its own title meanwhile: that one returns.
      if (document.title !== news && document.title !== ownTitle) ownTitle = document.title;
      showing = !showing;
      document.title = showing ? news : ownTitle;
    }, 1500);
  }

  function stopTitle() {
    if (!titleTimer) return;
    clearInterval(titleTimer);
    titleTimer = null;
    document.title = ownTitle;
  }

  function theme({ fill, fillHover, canvas, text, secondary, dark }) {
    root.style.setProperty('--fill', fill);
    root.style.setProperty('--fill-hover', fillHover);
    root.style.setProperty('--canvas', canvas);
    if (text) root.style.setProperty('--text', text);
    if (secondary) root.style.setProperty('--secondary', secondary);
    root.style.setProperty('--edge', dark ? 'rgb(255 255 255 / 14%)' : 'rgb(0 0 0 / 8%)');
  }

  // Focus moves into the window when the visitor opened it (the launcher, or the website's own button
  // or link calling open), never when the website opens it by itself, such as on page load.
  function open({ focus = navigator.userActivation?.isActive ?? true } = {}) {
    if (!ready) return void (openWhenReady = true);
    if (opened || !available) return;
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
      available = data.available !== false;
      window.tallport.available = available;
      name = data.label;
      label.textContent = name;
      frame.title = name;
      theme(data);
      launcher.hidden = !available;
      for (const [type, payload] of outbox.splice(0)) post(type, payload);
      if (openWhenReady) open({ focus: false });
    } else if (data.type === 'theme') theme(data);
    else if (data.type === 'unread') setUnread(data.count, data.label, data);
    else if (data.type === 'attention') flashTitle(data.title);
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
  window.tallport = Object.assign(run, { loaded: true, available: false });
  const init = queued.find(([command]) => command === 'init');
  if (init) run(...init);
  else if (script.dataset.widget) run('init');
  for (const [command, ...args] of queued) if (command !== 'init') run(command, ...args);
})();

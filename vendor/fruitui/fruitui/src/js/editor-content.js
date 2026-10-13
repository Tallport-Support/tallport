import { publishValue } from './control-bridge.js';

/** Marks an Alpine instance whose fruitEditor comes from the rich editor plugin. */
export const EDITOR_PLUGIN = Symbol.for('fruitui.editor');

/**
 * Requests for an Editor: fruit-editor-insert puts its content at the cursor and fruit-editor-set
 * replaces the content. A request carries { html }, { markdown } or both, and each editor takes the one
 * it writes: a rich editor the HTML, one in Markdown mode the Markdown. fruit-editor-mode with
 * { mode: 'markdown' } turns a rich editor into one in Markdown mode, its content converted, before the
 * dispatch returns. Dispatch them on (or inside) the editor, or on window with { target } naming its
 * textarea's id or name, as Livewire's dispatch() does.
 */
export function listenForEditorContent(root, control, { insert, set, toMarkdown, commit }) {
  const controller = new AbortController();
  const handle = event => {
    const { html, markdown, mode, target } = event.detail ?? {};
    if (event.currentTarget === window && target !== control.id && target !== control.name) return;
    if (control.matches(':disabled') || control.readOnly) return;
    if (event.type === 'fruit-editor-mode') {
      if (mode !== 'markdown' || root.dataset.fruitMode === 'markdown') return;
      root.dataset.fruitMode = 'markdown';
      toMarkdown();
    } else {
      const text = value => (value === undefined || value === null ? undefined : String(value));
      (event.type === 'fruit-editor-set' ? set : insert)({ html: text(html), markdown: text(markdown) });
    }
    commit();
  };
  for (const type of ['fruit-editor-insert', 'fruit-editor-set', 'fruit-editor-mode']) {
    root.addEventListener(type, handle, { signal: controller.signal });
    window.addEventListener(type, handle, { signal: controller.signal });
  }
  return () => controller.abort();
}

/** A request's HTML as plain text, for a field that holds Markdown or plain text. */
function plainText(html) {
  const body = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html').body;
  for (const line of body.querySelectorAll('br')) line.replaceWith('\n');
  for (const block of body.querySelectorAll('p, div, li, h1, h2, h3, h4, h5, h6, blockquote, pre'))
    block.append('\n\n');
  return body.textContent.replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * Without the rich editor plugin, the native textarea answers the same requests: in Markdown mode with
 * the request's Markdown (or its HTML as text), otherwise with its HTML (or its Markdown). Switched to
 * Markdown, it holds its HTML as text.
 */
export function fruitEditorFallback() {
  let stop, enter, control;
  return {
    init() {
      control = this.$el.querySelector('textarea[data-fruit-control]');
      if (!control) return;
      // data-fruit-enter="submit": Enter sends, Shift+Enter breaks the line, as in the rich editor.
      enter = event => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.defaultPrevented) return;
        if (this.$el.dataset.fruitEnter !== 'submit' || !control.form) return;
        event.preventDefault();
        control.form.requestSubmit();
      };
      control.addEventListener('keydown', enter);
      const content = ({ html, markdown }) =>
        this.$el.dataset.fruitMode === 'markdown'
          ? (markdown ?? (html ? plainText(html) : ''))
          : (html ?? markdown ?? '');
      stop = listenForEditorContent(this.$el, control, {
        // Rich to Markdown: the HTML as text, to review before it's saved.
        toMarkdown: () => publishValue(control, control.value ? plainText(control.value) : '', { commit: false }),
        insert: request => {
          const text = content(request);
          const start = control.selectionStart ?? control.value.length;
          const end = control.selectionEnd ?? start;
          publishValue(control, control.value.slice(0, start) + text + control.value.slice(end), { commit: false });
          control.setSelectionRange?.(start + text.length, start + text.length);
        },
        set: request => publishValue(control, content(request), { commit: false }),
        commit: () => control.dispatchEvent(new Event('change', { bubbles: true })),
      });
    },
    destroy() {
      stop?.();
      control?.removeEventListener('keydown', enter);
    },
  };
}

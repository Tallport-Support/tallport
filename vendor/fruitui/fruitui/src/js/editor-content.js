import { publishValue } from './control-bridge.js';

/** Marks an Alpine instance whose fruitEditor comes from the rich editor plugin. */
export const EDITOR_PLUGIN = Symbol.for('fruitui.editor');

/**
 * Content requests for an Editor: fruit-editor-insert puts { html } at the cursor and
 * fruit-editor-set replaces the content with { html }. Dispatch them on (or inside) the editor, or
 * on window with { target } naming its textarea's id or name, as Livewire's dispatch() does.
 */
export function listenForEditorContent(root, control, { insert, set, commit }) {
  const controller = new AbortController();
  const handle = event => {
    const { html = '', target } = event.detail ?? {};
    if (event.currentTarget === window && target !== control.id && target !== control.name) return;
    if (control.matches(':disabled') || control.readOnly) return;
    (event.type === 'fruit-editor-set' ? set : insert)(String(html));
    commit();
  };
  for (const type of ['fruit-editor-insert', 'fruit-editor-set']) {
    root.addEventListener(type, handle, { signal: controller.signal });
    window.addEventListener(type, handle, { signal: controller.signal });
  }
  return () => controller.abort();
}

/** Without the rich editor plugin, the native textarea answers the same requests. */
export function fruitEditorFallback() {
  let stop;
  return {
    init() {
      const control = this.$el.querySelector('textarea[data-fruit-control]');
      if (!control) return;
      stop = listenForEditorContent(this.$el, control, {
        insert: html => {
          const start = control.selectionStart ?? control.value.length;
          const end = control.selectionEnd ?? start;
          publishValue(control, control.value.slice(0, start) + html + control.value.slice(end), { commit: false });
          control.setSelectionRange?.(start + html.length, start + html.length);
        },
        set: html => publishValue(control, html, { commit: false }),
        commit: () => control.dispatchEvent(new Event('change', { bubbles: true })),
      });
    },
    destroy() {
      stop?.();
    },
  };
}

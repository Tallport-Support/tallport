import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { bridgeControl, publishValue } from './control-bridge.js';
import { listenForEditorContent, EDITOR_PLUGIN } from './editor-content.js';

/** Optional integration: import fruitui/editor explicitly on your existing Alpine. */
export default function fruitEditor(Alpine) {
  // FruitUI's plain-textarea fallback steps aside, whichever script registers first.
  Alpine[EDITOR_PLUGIN] = true;
  Alpine.data('fruitEditor', () => {
    // Keep ProseMirror outside Alpine's reactive proxy.
    let editor, control, surface, toolbar, dispose, click, blur, stopContent, lastValue, committedValue;
    const commands = {
      bold: chain => chain.toggleBold(),
      italic: chain => chain.toggleItalic(),
      bulletList: chain => chain.toggleBulletList(),
      orderedList: chain => chain.toggleOrderedList(),
      blockquote: chain => chain.toggleBlockquote(),
      undo: chain => chain.undo(),
      redo: chain => chain.redo(),
    };
    const refresh = () => {
      const blocked = control.matches(':disabled') || control.readOnly;
      editor.setEditable(!blocked, false);
      editor.view.dom.tabIndex = control.matches(':disabled') ? -1 : control.tabIndex;
      editor.view.dom.setAttribute('aria-disabled', String(control.matches(':disabled')));
      editor.view.dom.setAttribute('aria-readonly', String(control.readOnly));
      for (const button of toolbar.querySelectorAll('[data-fruit-command]')) {
        const command = button.dataset.fruitCommand;
        button.disabled = blocked || !commands[command] || !commands[command](editor.can().chain()).run();
        if (!['undo', 'redo'].includes(command)) button.setAttribute('aria-pressed', String(editor.isActive(command)));
      }
    };
    return {
      init() {
        control = this.$el.querySelector('textarea[data-fruit-control]');
        surface = this.$el.querySelector('.f-editor__surface');
        toolbar = this.$el.querySelector('.f-editor__toolbar');
        if (!control || !surface || !toolbar) return;
        lastValue = committedValue = control.value;
        editor = new Editor({
          element: surface,
          extensions: [StarterKit.configure({ link: { openOnClick: false } })],
          content: control.value,
          editorProps: { attributes: { class: 'f-prose', role: 'textbox', 'aria-multiline': 'true' } },
          onUpdate: () => {
            lastValue = editor.isEmpty ? '' : editor.getHTML();
            publishValue(control, lastValue, { commit: false });
            editor.view.dom.removeAttribute('aria-invalid');
          },
          onTransaction: () => {
            if (editor) refresh();
          },
        });
        dispose = bridgeControl(
          this,
          control,
          editor.view.dom,
          reason => {
            if (control.value !== lastValue) {
              editor.commands.setContent(control.value, { emitUpdate: false });
              lastValue = committedValue = control.value;
            }
            if (reason === 'reset') committedValue = control.value;
            refresh();
          },
          { presentation: surface, focusRoot: this.$el },
        );
        blur = event => {
          if (!this.$el.contains(event.relatedTarget) && control.value !== committedValue) {
            committedValue = control.value;
            control.dispatchEvent(new Event('change', { bubbles: true }));
          }
        };
        this.$el.addEventListener('focusout', blur);
        // Saved replies, drafts and signatures: insert at the cursor or replace, then commit.
        stopContent = listenForEditorContent(this.$el, control, {
          insert: html => editor.chain().focus().insertContent(html).run(),
          set: html => editor.commands.setContent(html, { emitUpdate: true }),
          commit: () => {
            committedValue = control.value;
            control.dispatchEvent(new Event('change', { bubbles: true }));
          },
        });
        click = event => {
          const button = event.target.closest('[data-fruit-command]');
          if (button && !button.disabled && commands[button.dataset.fruitCommand])
            commands[button.dataset.fruitCommand](editor.chain().focus()).run();
        };
        toolbar.addEventListener('click', click);
        toolbar.hidden = false;
        surface.hidden = false;
        control.hidden = true;
        refresh();
      },
      destroy() {
        dispose?.();
        stopContent?.();
        this.$el.removeEventListener('focusout', blur);
        toolbar?.removeEventListener('click', click);
        editor?.destroy();
        if (control) control.hidden = false;
        if (toolbar) toolbar.hidden = true;
        if (surface) surface.hidden = true;
      },
    };
  });
}

import { Editor, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { bridgeControl, publishValue, fruitId } from './control-bridge.js';
import { listenForEditorContent, EDITOR_PLUGIN } from './editor-content.js';
import { fruitPopup } from './popup.js';
import { fruitMessage } from './messages.js';

/** Images by address only: pasted data URLs are refused, so files go through the upload hook. */
const Image = Node.create({
  name: 'image',
  group: 'inline',
  inline: true,
  draggable: true,
  addAttributes() {
    return { src: { default: null }, alt: { default: null }, title: { default: null } };
  },
  parseHTML() {
    return [{ tag: 'img[src]:not([src^="data:"])' }];
  },
  renderHTML({ HTMLAttributes }) {
    return ['img', mergeAttributes(HTMLAttributes)];
  },
});

/** Optional integration: import fruitui/editor explicitly on your existing Alpine. */
export default function fruitEditor(Alpine) {
  // FruitUI's plain-textarea fallback steps aside, whichever script registers first.
  Alpine[EDITOR_PLUGIN] = true;
  Alpine.data('fruitEditor', () => {
    // Keep ProseMirror outside Alpine's reactive proxy.
    let editor, root, control, surface, toolbar, dispose, click, blur, stopContent, lastValue, committedValue;
    let popover, overlay, closePopover;
    const commands = {
      bold: chain => chain.toggleBold(),
      italic: chain => chain.toggleItalic(),
      bulletList: chain => chain.toggleBulletList(),
      orderedList: chain => chain.toggleOrderedList(),
      blockquote: chain => chain.toggleBlockquote(),
      undo: chain => chain.undo(),
      redo: chain => chain.redo(),
      clear: chain => chain.unsetAllMarks().clearNodes(),
      // Link and image ask for an address in a popover; these check that they can apply.
      link: chain => chain.setLink({ href: 'https://example.com' }),
      image: chain => chain.insertContent({ type: 'image', attrs: { src: 'https://example.com/i.png' } }),
    };
    const commit = () => {
      committedValue = control.value;
      control.dispatchEvent(new Event('change', { bubbles: true }));
    };
    /** A small popover by the toolbar button: one address field, then apply (and remove for links). */
    const ask = (button, kind) => {
      closePopover?.();
      const link = kind === 'link';
      const current = link ? editor.getAttributes('link').href || '' : '';
      popover = document.createElement('form');
      popover.className = 'f-editor__popover';
      popover.setAttribute('role', 'dialog');
      popover.id = fruitId('fruit-editor-popover');
      const label = fruitMessage(root, link ? 'link-label' : 'image-label', link ? 'Link Address' : 'Image Address');
      popover.setAttribute('aria-label', label);
      const field = document.createElement('input');
      field.className = 'f-input';
      field.type = 'url';
      field.required = !link || !current;
      field.value = current;
      field.placeholder = 'https://';
      field.setAttribute('aria-label', label);
      const apply = document.createElement('button');
      apply.className = 'f-button f-button--primary f-button--small';
      apply.type = 'submit';
      apply.textContent = fruitMessage(root, link ? 'apply-label' : 'insert-label', link ? 'Apply' : 'Insert');
      popover.append(field, apply);
      if (link && current) {
        const remove = document.createElement('button');
        remove.className = 'f-button f-button--ghost f-button--small';
        remove.type = 'button';
        remove.textContent = fruitMessage(root, 'remove-link-label', 'Remove Link');
        remove.addEventListener('click', () => {
          editor.chain().focus().extendMarkRange('link').unsetLink().run();
          commit();
          closePopover(false);
        });
        popover.append(remove);
      }
      popover.addEventListener('submit', event => {
        event.preventDefault();
        const href = field.value.trim();
        if (link) {
          const chain = editor.chain().focus().extendMarkRange('link');
          if (!href) chain.unsetLink().run();
          else if (editor.state.selection.empty && !current)
            editor
              .chain()
              .focus()
              .insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] })
              .run();
          else chain.setLink({ href }).run();
        } else if (href)
          editor
            .chain()
            .focus()
            .insertContent({ type: 'image', attrs: { src: href } })
            .run();
        commit();
        closePopover(false);
      });
      popover.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        closePopover(true);
      });
      toolbar.append(popover);
      overlay = fruitPopup(popover, button, { start: true });
      overlay.show();
      button.setAttribute('aria-expanded', 'true');
      closePopover = returnToButton => {
        overlay?.destroy();
        popover?.remove();
        popover = overlay = closePopover = null;
        button.setAttribute('aria-expanded', 'false');
        if (returnToButton) button.focus();
      };
      field.focus();
    };
    /** With data-fruit-paste="plain" (read on every paste), pasted formatting is dropped: text only. */
    const pastePlain = event => {
      if (root.dataset.fruitPaste !== 'plain') return false;
      const text = event.clipboardData?.getData('text/plain');
      if (!text) return false;
      const escape = value => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const html = text
        .replace(/\r\n?/g, '\n')
        .split(/\n{2,}/)
        .map(paragraph => `<p>${escape(paragraph).replace(/\n/g, '<br>')}</p>`)
        .join('');
      editor
        .chain()
        .focus()
        .insertContent(html, { parseOptions: { preserveWhitespace: false } })
        .run();
      return true;
    };
    /** Pasted or dropped image files go to the application, which uploads them and inserts the address. */
    const upload = (files, position) => {
      const images = [...(files ?? [])].filter(file => file.type.startsWith('image/'));
      if (!images.length) return false;
      const request = new CustomEvent('fruit-editor-upload', {
        bubbles: true,
        cancelable: true,
        detail: {
          files: images,
          insert: (src, alt = '') => {
            const at = Math.min(position ?? editor.state.selection.from, editor.state.doc.content.size);
            editor.chain().focus().insertContentAt(at, { type: 'image', attrs: { src, alt } }).run();
            commit();
          },
        },
      });
      control.dispatchEvent(request);
      // Unclaimed files are not pasted as data: images.
      return true;
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
        if (['link', 'image'].includes(command)) button.setAttribute('aria-haspopup', 'dialog');
        else if (!['undo', 'redo', 'clear'].includes(command))
          button.setAttribute('aria-pressed', String(editor.isActive(command)));
      }
    };
    return {
      init() {
        root = this.$el;
        control = this.$el.querySelector('textarea[data-fruit-control]');
        surface = this.$el.querySelector('.f-editor__surface');
        toolbar = this.$el.querySelector('.f-editor__toolbar');
        if (!control || !surface || !toolbar) return;
        lastValue = committedValue = control.value;
        editor = new Editor({
          element: surface,
          extensions: [StarterKit.configure({ link: { openOnClick: false } }), Image],
          content: control.value,
          editorProps: {
            attributes: { class: 'f-prose', role: 'textbox', 'aria-multiline': 'true' },
            handlePaste: (view, event) =>
              upload(event.clipboardData?.files, view.state.selection.from) || pastePlain(event),
            handleDrop: (view, event) =>
              upload(event.dataTransfer?.files, view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos),
          },
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
          commit,
        });
        click = event => {
          const button = event.target.closest('[data-fruit-command]');
          if (!button || button.disabled || !commands[button.dataset.fruitCommand]) return;
          const command = button.dataset.fruitCommand;
          if (command === 'link' || command === 'image') ask(button, command);
          else commands[command](editor.chain().focus()).run();
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
        closePopover?.();
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

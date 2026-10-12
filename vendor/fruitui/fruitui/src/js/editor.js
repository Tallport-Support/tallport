import { Editor, Extension, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { bridgeControl, publishValue, fruitId } from './control-bridge.js';
import { listenForEditorContent, EDITOR_PLUGIN } from './editor-content.js';
import { markdownEditing } from './editor-markdown.js';
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

/**
 * The editor's schema. Without data-fruit-formats it allows everything; with it (a channel's formats,
 * such as "bold italic link"), only those, so shortcuts and pasted content cannot add other markup.
 */
function extensions(formats) {
  const allowed = formats === undefined ? null : new Set(formats.split(/\s+/).filter(Boolean));
  const has = name => !allowed || allowed.has(name);
  const lists = has('bulletList') || has('orderedList');
  const off = name => (has(name) ? undefined : false);
  return [
    StarterKit.configure({
      bold: off('bold'),
      italic: off('italic'),
      blockquote: off('blockquote'),
      bulletList: off('bulletList'),
      orderedList: off('orderedList'),
      listItem: lists ? undefined : false,
      listKeymap: lists ? undefined : false,
      link: has('link') ? { openOnClick: false } : false,
      // Markup without a toolbar command exists only in the unrestricted editor.
      ...(allowed && {
        heading: false,
        code: false,
        codeBlock: false,
        strike: false,
        underline: false,
        horizontalRule: false,
      }),
    }),
    ...(has('image') ? [Image] : []),
    EnterSubmits,
  ];
}

/**
 * With data-fruit-enter="submit" (a chat), Enter sends the form and Shift+Enter breaks the line;
 * inside a list or code block Enter keeps its usual meaning. An input method composing is left alone.
 */
const EnterSubmits = Extension.create({
  name: 'fruitEnterSubmits',
  priority: 1000,
  addKeyboardShortcuts() {
    const submit = () => {
      const root = this.editor.view.dom.closest('.f-editor');
      if (root?.dataset.fruitEnter !== 'submit' || this.editor.view.composing) return false;
      if (this.editor.isActive('listItem') || this.editor.isActive('codeBlock')) return false;
      const form = root.querySelector('textarea[data-fruit-control]')?.form;
      if (!form) return false;
      form.requestSubmit();
      return true;
    };
    return { Enter: submit, 'Mod-Enter': submit };
  },
});

/** Optional integration: import fruitui/editor explicitly on your existing Alpine. */
export default function fruitEditor(Alpine) {
  // FruitUI's plain-textarea fallback steps aside, whichever script registers first.
  Alpine[EDITOR_PLUGIN] = true;
  Alpine.data('fruitEditor', () => {
    // Keep ProseMirror outside Alpine's reactive proxy.
    let editor, root, control, surface, toolbar, dispose, click, blur, stopContent, lastValue, committedValue;
    let popover, overlay, closePopover, formatting, toggleFormatting, markdown;
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
    /**
     * A small popover by the toolbar button: one address field, then apply (and remove for a link that
     * has one). Each mode says what the address is now and what Apply and Remove do.
     */
    const ask = (button, kind, { current = '', apply, remove }) => {
      closePopover?.();
      const link = kind === 'link';
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
      const confirm = document.createElement('button');
      confirm.className = 'f-button f-button--primary f-button--small';
      confirm.type = 'submit';
      confirm.textContent = fruitMessage(root, link ? 'apply-label' : 'insert-label', link ? 'Apply' : 'Insert');
      popover.append(field, confirm);
      if (link && current && remove) {
        const removeButton = document.createElement('button');
        removeButton.className = 'f-button f-button--ghost f-button--small';
        removeButton.type = 'button';
        removeButton.textContent = fruitMessage(root, 'remove-link-label', 'Remove Link');
        removeButton.addEventListener('click', () => {
          remove();
          commit();
          closePopover(false);
        });
        popover.append(removeButton);
      }
      popover.addEventListener('submit', event => {
        event.preventDefault();
        const href = field.value.trim();
        if (href || link) apply(href);
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
    /** A rich link: the address of the link at the selection, applied, inserted or removed. */
    const askLink = button => {
      const current = editor.getAttributes('link').href || '';
      ask(button, 'link', {
        current,
        apply: href => {
          const chain = editor.chain().focus().extendMarkRange('link');
          if (!href) chain.unsetLink().run();
          else if (editor.state.selection.empty && !current)
            editor
              .chain()
              .focus()
              .insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] })
              .run();
          else chain.setLink({ href }).run();
        },
        remove: () => editor.chain().focus().extendMarkRange('link').unsetLink().run(),
      });
    };
    const askImage = button =>
      ask(button, 'image', {
        apply: href =>
          editor
            .chain()
            .focus()
            .insertContent({ type: 'image', attrs: { src: href } })
            .run(),
      });
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
      if (!editor.schema.nodes.image) return false;
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
      // The textarea's placeholder shows in the empty editor, and is announced as its placeholder.
      const placeholder = control.getAttribute('placeholder');
      if (placeholder) {
        editor.view.dom.setAttribute('data-placeholder', placeholder);
        editor.view.dom.setAttribute('aria-placeholder', placeholder);
      }
      editor.view.dom.toggleAttribute('data-empty', markdown ? !markdown.value() : editor.isEmpty);
      const blocked = control.matches(':disabled') || control.readOnly;
      editor.setEditable(!blocked, false);
      editor.view.dom.tabIndex = control.matches(':disabled') ? -1 : control.tabIndex;
      editor.view.dom.setAttribute('aria-disabled', String(control.matches(':disabled')));
      // A disabled field takes no content, so its toolbar, application buttons included, is inert.
      toolbar.inert = control.matches(':disabled');
      editor.view.dom.setAttribute('aria-readonly', String(control.readOnly));
      for (const button of toolbar.querySelectorAll('[data-fruit-command]')) {
        const command = button.dataset.fruitCommand;
        const available = markdown
          ? markdown.can(command)
          : commands[command] && commands[command](editor.can().chain()).run();
        button.disabled = blocked || !available;
        if (['link', 'image'].includes(command)) button.setAttribute('aria-haspopup', 'dialog');
        else if (!['undo', 'redo', 'clear'].includes(command))
          button.setAttribute('aria-pressed', String(markdown ? markdown.pressed(command) : editor.isActive(command)));
      }
    };
    /** The formatting bar: always, or (inline, a chat's field) when its button asks for it. */
    const showToolbar = () => {
      formatting = root.querySelector('[data-fruit-formatting]');
      if (root.classList.contains('f-editor--inline')) {
        toggleFormatting = () => {
          toolbar.hidden = !toolbar.hidden;
          formatting.setAttribute('aria-expanded', String(!toolbar.hidden));
        };
        formatting?.addEventListener('click', toggleFormatting);
        if (formatting) formatting.hidden = false;
      } else toolbar.hidden = false;
    };
    /** HTML, such as pasted content, read into the field's formats. */
    const readHtml = html => {
      const reader = new Editor({
        element: document.createElement('div'),
        extensions: extensions(root.dataset.fruitFormats),
      });
      reader.commands.setContent(html, { emitUpdate: false });
      const content = reader.getJSON();
      reader.destroy();
      return content;
    };
    return {
      init() {
        root = this.$el;
        control = this.$el.querySelector('textarea[data-fruit-control]');
        surface = this.$el.querySelector('.f-editor__surface');
        toolbar = this.$el.querySelector('.f-editor__toolbar');
        if (!control || !surface || !toolbar) return;
        // Markdown mode: the Markdown as written, its syntax in view and styled (editor-markdown.js).
        const formats = root.dataset.fruitFormats;
        if (root.dataset.fruitMode === 'markdown')
          markdown = markdownEditing({
            root,
            control,
            ask,
            commit,
            read: readHtml,
            editor: () => editor,
            images: formats === undefined || formats.split(/\s+/).includes('image'),
          });
        lastValue = committedValue = control.value;
        editor = new Editor({
          element: surface,
          extensions: markdown?.extensions ?? extensions(formats),
          content: markdown ? markdown.content(control.value) : control.value,
          ...markdown?.options,
          editorProps: {
            attributes: {
              class: markdown ? 'f-editor__markdown' : 'f-prose',
              role: 'textbox',
              'aria-multiline': 'true',
            },
            handlePaste: (view, event) =>
              markdown
                ? markdown.paste(event)
                : upload(event.clipboardData?.files, view.state.selection.from) || pastePlain(event),
            handleDrop: (view, event, slice, moved) =>
              markdown
                ? markdown.drop(event, moved)
                : upload(event.dataTransfer?.files, view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos),
          },
          onUpdate: () => {
            lastValue = markdown ? markdown.value() : editor.isEmpty ? '' : editor.getHTML();
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
              editor.commands.setContent(markdown ? markdown.content(control.value) : control.value, {
                emitUpdate: false,
              });
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
        // Saved replies, drafts and signatures: insert at the cursor or replace, then commit. A
        // request's HTML, or its Markdown as text when it has no HTML.
        const content = ({ html, markdown: text }) => html ?? (text ? { type: 'text', text } : '');
        stopContent = listenForEditorContent(this.$el, control, {
          insert: request => editor.chain().focus().insertContent(content(request)).run(),
          set: request => editor.commands.setContent(content(request), { emitUpdate: true }),
          ...(markdown && { insert: markdown.insert, set: markdown.set }),
          commit,
        });
        click = event => {
          const button = event.target.closest('[data-fruit-command]');
          if (!button || button.disabled || !commands[button.dataset.fruitCommand]) return;
          const command = button.dataset.fruitCommand;
          if (markdown) markdown.run(command, button);
          else if (command === 'link') askLink(button);
          else if (command === 'image') askImage(button);
          else commands[command](editor.chain().focus()).run();
        };
        toolbar.addEventListener('click', click);
        showToolbar();
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
        formatting?.removeEventListener('click', toggleFormatting);
        if (formatting) formatting.hidden = true;
        editor?.destroy();
        if (control) control.hidden = false;
        if (toolbar) {
          toolbar.hidden = true;
          toolbar.inert = false;
        }
        if (surface) surface.hidden = true;
      },
    };
  });
}

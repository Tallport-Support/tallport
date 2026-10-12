import { Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Plugin, PluginKey, TextSelection } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { Fragment, Slice } from '@tiptap/pm/model';
import { closeHistory } from '@tiptap/pm/history';
import { parser, GFM } from '@lezer/markdown';

/**
 * An Editor in Markdown mode (data-fruit-mode="markdown"): the person writes Markdown, and the value is
 * that Markdown, as written. Each line is a paragraph of plain text. The syntax stays in view, dimmed,
 * and shows its effect on the text: bold text is bold, a link's text has the link color, a heading is
 * larger, a quote sits on a bar and code is monospaced. Lezer's Markdown parser reads it as CommonMark
 * with GitHub's extensions, as Laravel's Str::markdown renders it. The toolbar writes syntax around the
 * selection, Enter continues a list or quote, and pasted formatting arrives as Markdown. Nothing is
 * converted on the way in or out.
 */

const markdown = parser.configure(GFM);
const task = /^(\s*)([-+*]|(\d+)([.)]))\s\[[ xX]\]\s/;
const list = /^(\s*)([-+*]|(\d+)([.)]))\s/;
const quote = /^(\s*)>\s?/;

/** Markdown as the editor's document: a paragraph for each line. */
export const markdownContent = text => ({
  type: 'doc',
  content: String(text ?? '')
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map(line => ({ type: 'paragraph', ...(line && { content: [{ type: 'text', text: line }] }) })),
});

/** The same lines as a slice that joins the lines around where it goes. */
const lineSlice = (schema, text) =>
  new Slice(
    Fragment.from(text.split('\n').map(line => schema.nodes.paragraph.create(null, line ? schema.text(line) : null))),
    1,
    1,
  );

/**
 * Offsets in the Markdown and positions in the document. Line n's paragraph opens at its offset plus n,
 * and its text starts one position later.
 */
function lineStarts(doc) {
  const starts = [];
  let offset = 0;
  doc.forEach(line => {
    starts.push(offset);
    offset += line.content.size + 1;
  });
  return starts;
}
function lineAt(starts, offset) {
  let low = 0;
  let high = starts.length - 1;
  while (low < high) {
    const middle = (low + high + 1) >> 1;
    if (starts[middle] <= offset) low = middle;
    else high = middle - 1;
  }
  return low;
}
const toPosition = (starts, offset) => offset + lineAt(starts, offset) + 1;
function toOffset(doc, position) {
  const $position = doc.resolve(position);
  // Between paragraphs (a whole-document selection): the end of the line before.
  return Math.max(0, position - $position.index(0) - 1);
}

/** Syntax, dimmed; spans of text, styled by what they are; and whole lines, by the block they're in. */
const syntax = new Set([
  'HeaderMark',
  'QuoteMark',
  'ListMark',
  'TaskMarker',
  'EmphasisMark',
  'StrikethroughMark',
  'CodeMark',
  'CodeInfo',
  'LinkMark',
  'LinkTitle',
  'LinkLabel',
  'TableDelimiter',
  'HorizontalRule',
  'HTMLTag',
  'HTMLBlock',
  'Comment',
  'CommentBlock',
  'ProcessingInstruction',
  'ProcessingInstructionBlock',
]);
const textStyles = {
  StrongEmphasis: 'strong',
  Emphasis: 'emphasis',
  Strikethrough: 'strike',
  InlineCode: 'code',
  Link: 'link',
  Image: 'link',
  Autolink: 'link',
};
const lineStyles = {
  ATXHeading1: 'h1',
  ATXHeading2: 'h2',
  ATXHeading3: 'h3',
  ATXHeading4: 'h4',
  ATXHeading5: 'h5',
  ATXHeading6: 'h6',
  SetextHeading1: 'h1',
  SetextHeading2: 'h2',
  Blockquote: 'quote',
  FencedCode: 'code',
  CodeBlock: 'code',
};
// Spell checking stays out of code and addresses.
const literal = { spellcheck: 'false' };

function decorate(doc, tree, starts) {
  const found = [];
  const span = (from, to, name, attributes) => {
    if (from < to)
      found.push(
        Decoration.inline(toPosition(starts, from), toPosition(starts, to), {
          class: `f-editor__${name}`,
          ...attributes,
        }),
      );
  };
  tree.iterate({
    enter: node => {
      const { name, from, to } = node;
      if (lineStyles[name]) {
        const last = lineAt(starts, Math.max(from, to - 1));
        for (let line = lineAt(starts, from); line <= last; line++) {
          const at = starts[line] + line;
          found.push(
            Decoration.node(at, at + doc.child(line).nodeSize, {
              class: `f-editor__line--${lineStyles[name]}`,
              ...(lineStyles[name] === 'code' && literal),
            }),
          );
        }
      }
      if (textStyles[name]) span(from, to, textStyles[name], name === 'InlineCode' ? literal : undefined);
      if (syntax.has(name)) span(from, to, 'syntax');
      // An escape's backslash; a link's address; a bare address, which GitHub links.
      if (name === 'Escape') span(from, from + 1, 'syntax');
      if (name === 'URL') span(from, to, ['Link', 'Image'].includes(node.node.parent?.name) ? 'url' : 'link', literal);
    },
  });
  return DecorationSet.create(doc, found);
}

/** The document read as Markdown: its text, its lines and its syntax tree, kept with each change. */
const key = new PluginKey('fruitMarkdown');
function read(doc) {
  const lines = [];
  doc.forEach(line => lines.push(line.textContent));
  const text = lines.join('\n');
  const starts = lineStarts(doc);
  const tree = markdown.parse(text);
  return { text, starts, tree, decorations: decorate(doc, tree, starts) };
}
const highlighting = new Plugin({
  key,
  state: {
    init: (config, state) => read(state.doc),
    apply: (transaction, value) => (transaction.docChanged ? read(transaction.doc) : value),
  },
  props: { decorations: state => key.getState(state).decorations },
});

/** The innermost node of one of the kinds that holds the whole range. */
function enclosing(tree, from, to, names) {
  for (let node = tree.resolveInner(from, 1); node; node = node.parent)
    if (names.includes(node.name) && node.from <= from && node.to >= to) return node;
  return null;
}

/** The lines the range touches, whole. A range that ends at the start of a line doesn't include it. */
function lineRange(text, start, end) {
  const from = text.lastIndexOf('\n', start - 1) + 1;
  const last = end > start && text[end - 1] === '\n' ? end - 1 : end;
  const next = text.indexOf('\n', last);
  const to = next < 0 ? text.length : next;
  return { from, to, lines: text.slice(from, to).split('\n') };
}
const prefixed = (kind, line) =>
  kind === 'blockquote'
    ? quote.test(line)
    : kind === 'orderedList'
      ? Boolean(list.exec(line)?.[3])
      : Boolean(list.exec(line)) && !list.exec(line)[3];
/** Every line with text in the range starts with the kind's syntax. */
function prefixedLines(kind, lines) {
  const filled = lines.filter(line => line.trim());
  return filled.length > 0 && filled.every(line => prefixed(kind, line));
}

/** An offset after removing ranges of the text. */
const shift = (offset, removed) =>
  removed.reduce((at, { from, to }) => at - Math.min(Math.max(offset - from, 0), to - from), offset);

/** Code copied from a code editor or a terminal: preformatted text, Markdown as written. */
function preformatted(html) {
  const body = new DOMParser().parseFromString(html, 'text/html').body;
  const walker = body.ownerDocument.createTreeWalker(body, NodeFilter.SHOW_TEXT);
  let found = false;
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (!node.data.trim()) continue;
    if (!node.parentElement.closest('pre, [style*="white-space: pre"], [style*="white-space:pre"]')) return false;
    found = true;
  }
  return found;
}
/** Content with formatting beyond paragraphs of text. */
const formatted = content =>
  (content ?? []).some(
    node => node.marks?.length || !['paragraph', 'text', 'hardBreak'].includes(node.type) || formatted(node.content),
  );

/** Text written as Markdown that reads as itself: syntax characters escaped, not underscores inside words. */
function escapeText(text) {
  return text
    .replace(/\\/g, '\\\\')
    .replace(/[*`[\]~]/g, '\\$&')
    .replace(/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/gu, '\\_');
}

const delimiters = { bold: ['**', '**'], italic: ['*', '*'], strike: ['~~', '~~'], code: ['`', '`'] };

/** A run of inline content: marks open and close where they change, outside the words' spaces. */
function inline(content = []) {
  let out = '';
  let open = [];
  const closing = mark => (mark.type === 'link' ? `](${mark.attrs?.href ?? ''})` : delimiters[mark.type][1]);
  const opening = mark => (mark.type === 'link' ? '[' : delimiters[mark.type][0]);
  const supported = marks => (marks ?? []).filter(mark => mark.type === 'link' || delimiters[mark.type]);
  const close = (count, after = '') => {
    for (const mark of open.splice(open.length - count).reverse()) out += closing(mark);
    out += after;
  };
  for (const node of content) {
    if (node.type === 'hardBreak') {
      close(open.length);
      out += '\n';
      continue;
    }
    if (node.type === 'image') {
      close(open.length);
      out += `![${escapeText(node.attrs?.alt ?? '')}](${node.attrs?.src ?? ''})`;
      continue;
    }
    if (node.type !== 'text') continue;
    const marks = supported(node.marks);
    const same = (a, b) => a.type === b.type && (a.attrs?.href ?? '') === (b.attrs?.href ?? '');
    let keep = 0;
    while (keep < open.length && keep < marks.length && same(open[keep], marks[keep])) keep++;
    const code = marks.some(mark => mark.type === 'code');
    const text = code ? node.text : escapeText(node.text);
    const [, lead, body, trail] = /^(\s*)(.*?)(\s*)$/s.exec(text);
    // Spaces alone carry no marks: delimiters around nothing would read as text.
    if (!body) {
      close(open.length, text);
      open = [];
      continue;
    }
    close(open.length - keep);
    out += lead;
    for (const mark of marks.slice(keep)) out += opening(mark);
    open = marks;
    out += body;
    if (trail) {
      close(open.length, trail);
      open = [];
    }
  }
  close(open.length);
  return out;
}

/** Lines that would read as syntax at the start of a paragraph line are escaped. */
const paragraph = node =>
  inline(node.content)
    .split('\n')
    .map(line => line.replace(/^(\s*)(#{1,6}\s|>|[-+]\s|\d+[.)]\s)/, (match, space, syntax) => `${space}\\${syntax}`))
    .join('\n');

const indentLines = (text, first, rest) =>
  text
    .split('\n')
    .map((line, index) => (index ? (line ? rest + line : line) : first + line))
    .join('\n');

/** A document's blocks as Markdown, one blank line between them, list items tight. */
function blocks(content = []) {
  return content
    .map(block)
    .filter(text => text !== null)
    .join('\n\n');
}

function block(node) {
  switch (node.type) {
    case 'paragraph':
      return paragraph(node);
    case 'heading':
      return `${'#'.repeat(node.attrs?.level ?? 1)} ${inline(node.content)}`;
    case 'blockquote':
      return blocks(node.content)
        .split('\n')
        .map(line => (line ? `> ${line}` : '>'))
        .join('\n');
    case 'bulletList':
    case 'orderedList': {
      let number = node.attrs?.start ?? 1;
      return (node.content ?? [])
        .map(item => {
          const marker = node.type === 'orderedList' ? `${number++}. ` : '- ';
          return indentLines(
            (item.content ?? [])
              .map(block)
              .filter(text => text !== null)
              .join('\n'),
            marker,
            ' '.repeat(marker.length),
          );
        })
        .join('\n');
    }
    case 'codeBlock':
      return `\`\`\`${node.attrs?.language ?? ''}\n${(node.content ?? []).map(text => text.text).join('')}\n\`\`\``;
    case 'horizontalRule':
      return '---';
    default:
      return node.content ? blocks(node.content) : null;
  }
}

/** A ProseMirror document (JSON), such as pasted content read into a field's formats, as Markdown. */
export function markdownFromDocument(document) {
  return blocks(document.content)
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Markdown editing for the Editor's Tiptap surface. `read` turns HTML into a document within the field's
 * formats; `ask` opens the address popover; `commit` sends the change event; `editor` returns the
 * Tiptap editor, once there is one. Returns the extensions and options to create it with, and what the
 * Editor asks of a mode: its value, its commands and their state, content requests, paste and drop.
 */
export function markdownEditing({ root, control, read: readHtml, ask, commit, editor, images }) {
  const toMarkdown = html => markdownFromDocument(readHtml(html));
  const blocked = () => control.matches(':disabled') || control.readOnly;
  const current = () => {
    const { state } = editor();
    const { text, tree } = key.getState(state);
    return {
      text,
      tree,
      start: toOffset(state.doc, state.selection.from),
      end: toOffset(state.doc, state.selection.to),
    };
  };
  /**
   * Replace ranges of the Markdown (offsets of the current text) as one step of undo, then select a
   * range of the new text, or keep the selection where the change moved it.
   */
  const change = (changes, select) => {
    const { view } = editor();
    const { state } = view;
    const { starts } = key.getState(state);
    const transaction = state.tr;
    for (const { from, to = from, insert = '' } of [...changes].sort((a, b) => b.from - a.from)) {
      const start = toPosition(starts, from);
      const end = toPosition(starts, to);
      if (insert.includes('\n')) transaction.replace(start, end, lineSlice(state.schema, insert));
      else if (insert) transaction.insertText(insert, start, end);
      else if (end > start) transaction.delete(start, end);
    }
    if (select) {
      const next = lineStarts(transaction.doc);
      transaction.setSelection(
        TextSelection.create(transaction.doc, toPosition(next, select[0]), toPosition(next, select[1])),
      );
    }
    view.focus();
    view.dispatch(closeHistory(transaction).scrollIntoView());
  };
  /** Content at the selection, the caret after it. */
  const insert = text => {
    const { start, end } = current();
    change([{ from: start, to: end, insert: text }], [start + text.length, start + text.length]);
  };
  const emphasis = { bold: ['StrongEmphasis', '**'], italic: ['Emphasis', '*'] };
  /**
   * Bold (**) and italic (*): off text that has them, else around the selection, outside the spaces
   * at its ends. ***text*** is both, so italic on bold text adds a star on each side, and bold off
   * leaves the italic.
   */
  const emphasize = kind => {
    const [name, marker] = emphasis[kind];
    const { text, tree, start, end } = current();
    const node = enclosing(tree, start, end, [name]);
    if (node) {
      const removed = [node.firstChild, node.lastChild].map(({ from, to }) => ({ from, to }));
      return change(removed, [shift(start, removed), shift(end, removed)]);
    }
    const selected = text.slice(start, end);
    const from = start + selected.length - selected.trimStart().length;
    const to = Math.max(from, end - (selected.length - selected.trimEnd().length));
    change(
      [
        { from, insert: marker },
        { from: to, insert: marker },
      ],
      [from + marker.length, to + marker.length],
    );
  };
  /** Lists and quotes start each line of the selection; when every line has them, they come off. */
  const prefix = kind => {
    const { text, start, end } = current();
    const { from, to, lines } = lineRange(text, start, end);
    const off = prefixedLines(kind, lines);
    let number = 0;
    const next = lines
      .map(line => {
        if (!line.trim()) return line;
        if (kind === 'blockquote') return off ? line.replace(quote, '$1') : `> ${line}`;
        const bare = line.replace(list, '$1');
        if (off) return bare;
        const indent = bare.match(/^\s*/)[0];
        return `${indent}${kind === 'orderedList' ? `${++number}.` : '-'} ${bare.slice(indent.length)}`;
      })
      .join('\n');
    change([{ from, to, insert: next }], [from, from + next.length]);
  };
  /**
   * Remove Formatting: the syntax of the formatting the selection touches (the caret's line without
   * one), and the headings, lists and quotes of its lines. Text and addresses without syntax stay.
   */
  const clear = () => {
    const { text, tree, start, end } = current();
    const lines = lineRange(text, start, end);
    const range = start === end ? lines : { from: start, to: end };
    const removed = [];
    tree.iterate({
      from: lines.from,
      to: lines.to,
      enter: node => {
        const { name, from, to } = node;
        const touched = from < range.to && to > range.from;
        if (['HeaderMark', 'QuoteMark', 'ListMark', 'TaskMarker'].includes(name) && from >= lines.from) {
          // Its space goes with it.
          removed.push({ from, to: text[to] === ' ' ? to + 1 : to });
        } else if (
          touched &&
          ['StrongEmphasis', 'Emphasis', 'Strikethrough', 'InlineCode', 'Autolink'].includes(name)
        ) {
          removed.push({ from, to: node.node.firstChild.to }, { from: node.node.lastChild.from, to });
        } else if (touched && ['Link', 'Image'].includes(name)) {
          // A link's text stays: everything from its "]" on goes, with its "[" or "![".
          const [open, close] = node.node.getChildren('LinkMark');
          removed.push({ from, to: open.to }, ...(close ? [{ from: close.from, to }] : []));
        }
      },
    });
    const merged = removed
      .sort((a, b) => a.from - b.from)
      .reduce((all, next) => {
        const last = all.at(-1);
        if (last && next.from <= last.to) last.to = Math.max(last.to, next.to);
        else all.push({ ...next });
        return all;
      }, []);
    change(merged, [shift(start, merged), shift(end, merged)]);
  };
  /** The link the selection is in: its text, between its first two marks, and its address. */
  const linkAround = () => {
    const { text, tree, start, end } = current();
    const node = enclosing(tree, start, end, ['Link']);
    if (!node) return { text, start, end };
    const [open, close, parenthesis] = node.getChildren('LinkMark');
    const url = node.getChild('URL');
    return { text, start, end, node, open, close, parenthesis, url };
  };
  const commands = {
    bold: () => emphasize('bold'),
    italic: () => emphasize('italic'),
    bulletList: () => prefix('bulletList'),
    orderedList: () => prefix('orderedList'),
    blockquote: () => prefix('blockquote'),
    clear,
    undo: () => editor().chain().focus().undo().run(),
    redo: () => editor().chain().focus().redo().run(),
    link: button => {
      const { text, start, end, node, open, close, parenthesis, url } = linkAround();
      const unlink = () =>
        change(
          [
            { from: node.from, to: open.to },
            { from: close.from, to: node.to },
          ],
          [node.from, node.from + close.from - open.to],
        );
      ask(button, 'link', {
        current: url ? text.slice(url.from, url.to) : '',
        apply: href => {
          if (node && !href) unlink();
          else if (url) change([{ from: url.from, to: url.to, insert: href }]);
          else if (parenthesis) change([{ from: parenthesis.to, insert: href }]);
          else if (node) change([{ from: close.to, to: node.to, insert: `(${href})` }]);
          else if (href) {
            const selected = text.slice(start, end);
            insert(selected ? `[${selected}](${href})` : `<${href}>`);
          }
        },
        remove: node && unlink,
      });
    },
    image: button => {
      const { text, start, end } = current();
      ask(button, 'image', { apply: href => insert(`![${text.slice(start, end)}](${href})`) });
    },
  };
  const run = (command, button) => {
    if (!blocked() && commands[command]) commands[command](button);
  };
  const allowed = command => !!root.querySelector(`[data-fruit-command="${command}"]:not(:disabled)`);
  const submit = () => {
    if (root.dataset.fruitEnter !== 'submit' || !control.form || blocked()) return false;
    control.form.requestSubmit();
    return true;
  };
  /**
   * Enter in a list or quote starts the next item; on an empty item, it ends the list. In code it's a
   * new line, and in a chat's field (data-fruit-enter="submit") it sends outside a list.
   */
  const enter = () => {
    if (editor().view.composing || blocked()) return false;
    const { text, tree, start, end } = current();
    const lineStart = text.lastIndexOf('\n', start - 1) + 1;
    const lineEnd = text.indexOf('\n', start) < 0 ? text.length : text.indexOf('\n', start);
    const line = text.slice(lineStart, lineEnd);
    const item = task.exec(line) || list.exec(line) || quote.exec(line);
    const code = enclosing(tree, start, start, ['FencedCode', 'CodeBlock']);
    if (item && !code && start === end && start >= lineStart + item[0].length) {
      const marker = item[0];
      if (!line.slice(marker.length).trim() && start === lineEnd) {
        change([{ from: lineStart, to: lineEnd, insert: item[1] }]);
        return true;
      }
      const next = (item[3] ? marker.replace(item[3], String(Number(item[3]) + 1)) : marker).replace(/\[[xX]\]/, '[ ]');
      insert(`\n${next}`);
      return true;
    }
    return code ? false : submit();
  };
  /** Images go to the application, which uploads them and inserts their Markdown where they were put. */
  const upload = (files, offset) => {
    const found = [...(files ?? [])].filter(file => file.type.startsWith('image/'));
    if (!found.length || !images) return false;
    control.dispatchEvent(
      new CustomEvent('fruit-editor-upload', {
        bubbles: true,
        cancelable: true,
        detail: {
          files: found,
          insert: (src, alt = '') => {
            const at = Math.min(offset, current().text.length);
            change([{ from: at, insert: `![${alt}](${src})` }]);
            commit();
          },
        },
      }),
    );
    return true;
  };
  /**
   * Pasted or dropped content as Markdown: formatting read into the field's formats and written as its
   * syntax; text, Markdown copied from this or another editor, and data-fruit-paste="plain" as it is.
   */
  const pasted = data => {
    const text = (data?.getData('text/plain') ?? '').replace(/\r\n?/g, '\n');
    const html = root.dataset.fruitPaste === 'plain' ? '' : data?.getData('text/html');
    if (!html || preformatted(html)) return text;
    const document = readHtml(html);
    return formatted(document.content) || !text ? markdownFromDocument(document) : text;
  };
  const Markdown = Extension.create({
    name: 'fruitMarkdown',
    priority: 1000,
    addProseMirrorPlugins: () => [highlighting],
    addKeyboardShortcuts() {
      const shortcut = command => () => {
        if (allowed(command)) run(command);
        return true;
      };
      return {
        Enter: enter,
        // A line break, without continuing a list.
        'Shift-Enter': () => this.editor.commands.splitBlock(),
        'Mod-Enter': submit,
        'Mod-b': shortcut('bold'),
        'Mod-i': shortcut('italic'),
      };
    },
  });
  return {
    extensions: [
      StarterKit.configure({
        paragraph: { HTMLAttributes: { class: 'f-editor__line' } },
        blockquote: false,
        bold: false,
        bulletList: false,
        code: false,
        codeBlock: false,
        gapcursor: false,
        hardBreak: false,
        heading: false,
        horizontalRule: false,
        italic: false,
        link: false,
        listItem: false,
        listKeymap: false,
        orderedList: false,
        strike: false,
        trailingNode: false,
        underline: false,
      }),
      Markdown,
    ],
    // Copied lines read as the Markdown they are.
    options: { coreExtensionOptions: { clipboardTextSerializer: { blockSeparator: '\n' } } },
    content: markdownContent,
    value: () => key.getState(editor().state).text,
    can: command => (command === 'undo' || command === 'redo' ? editor().can()[command]() : Boolean(commands[command])),
    pressed: command => {
      const { text, tree, start, end } = current();
      if (emphasis[command]) return Boolean(enclosing(tree, start, end, [emphasis[command][0]]));
      return prefixedLines(command, lineRange(text, start, end).lines);
    },
    run,
    /** Content requests: Markdown as it is, or HTML read into the field's formats. */
    insert: ({ markdown: text, html }) => insert(text ?? (html ? toMarkdown(html) : '')),
    // Replacing it all doesn't take focus: a draft or template can arrive from the server.
    set: ({ markdown: text, html }) =>
      editor().commands.setContent(markdownContent(text ?? (html ? toMarkdown(html) : '')), { emitUpdate: true }),
    paste: event => {
      if (upload(event.clipboardData?.files, current().start)) return true;
      const text = pasted(event.clipboardData);
      if (!text) return false;
      insert(text);
      return true;
    },
    drop: (event, moved) => {
      // Text dragged within the field moves as it is.
      if (moved) return false;
      const { view } = editor();
      const position = view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos;
      if (position === undefined) return false;
      const at = toOffset(view.state.doc, position);
      if (upload(event.dataTransfer?.files, at)) return true;
      const text = pasted(event.dataTransfer);
      if (!text) return false;
      change([{ from: at, insert: text }], [at, at + text.length]);
      return true;
    },
  };
}

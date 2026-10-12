import { publishValue } from './control-bridge.js';

/**
 * An Editor in Markdown mode (data-fruit-mode="markdown"): the person writes Markdown in the native
 * textarea, and its value is that Markdown, as written. The toolbar writes syntax around the
 * selection, Enter continues a list or quote, pasted formatting arrives as Markdown, and undo is the
 * browser's own. Nothing is converted on the way in or out.
 */

const list = /^(\s*)([-+*]|(\d+)([.)]))\s/;
const quote = /^(\s*)>\s?/;

/** Change the text between start and end, keeping the change in the browser's undo history. */
function edit(control, start, end, text, select) {
  control.focus();
  control.setSelectionRange(start, end);
  const done = text ? document.execCommand('insertText', false, text) : document.execCommand('delete');
  if (!done) {
    control.setRangeText(text, start, end, 'end');
    control.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (select) control.setSelectionRange(...select);
}

/** The lines the selection touches, whole. */
function lines(control) {
  const { value, selectionStart, selectionEnd } = control;
  const start = value.lastIndexOf('\n', selectionStart - 1) + 1;
  // A selection that ends at the start of a line doesn't include that line.
  const last = selectionEnd > selectionStart && value[selectionEnd - 1] === '\n' ? selectionEnd - 1 : selectionEnd;
  const next = value.indexOf('\n', last);
  const end = next < 0 ? value.length : next;
  return { start, end, lines: value.slice(start, end).split('\n') };
}

/**
 * Bold (**) and italic (*) around the selection; when it has them already, they come off. ***text***
 * is both, so italic on bold text adds a star on each side, and bold off leaves the italic.
 */
function emphasize(control, marker) {
  const { value, selectionStart: start, selectionEnd: end } = control;
  const selected = value.slice(start, end);
  const around = mark =>
    value.slice(start - mark.length, start) === mark && value.slice(end, end + mark.length) === mark;
  const bold = marker === '**';
  const on = bold ? around('**') : around('***') || (around('*') && !around('**')) || around('_');
  if (on) {
    const size = bold ? 2 : 1;
    return edit(control, start - size, end + size, selected, [start - size, end - size]);
  }
  // A selection that includes its own markers.
  const inner = bold ? /^\*\*([\s\S]+)\*\*$/ : /^(?:\*(?!\*)([\s\S]+)(?<!\*)\*|_([\s\S]+)_)$/;
  const match = inner.exec(selected);
  if (match) {
    const text = match[1] ?? match[2];
    return edit(control, start, end, text, [start, start + text.length]);
  }
  edit(control, start, end, `${marker}${selected}${marker}`, [start + marker.length, end + marker.length]);
}

/** Lists and quotes start each line of the selection; when every line has them, they come off. */
function prefix(control, kind) {
  const { start, end, lines: current } = lines(control);
  const filled = current.filter(line => line.trim());
  const has = line =>
    kind === 'blockquote'
      ? quote.test(line)
      : kind === 'orderedList'
        ? list.exec(line)?.[3]
        : list.exec(line) && !list.exec(line)[3];
  const off = filled.length > 0 && filled.every(has);
  let number = 0;
  const next = current.map(line => {
    if (!line.trim()) return line;
    if (kind === 'blockquote') return off ? line.replace(quote, '$1') : `> ${line}`;
    const text = line.replace(list, '$1');
    if (off) return text;
    const indent = text.match(/^\s*/)[0];
    return `${indent}${kind === 'orderedList' ? `${++number}.` : '-'} ${text.slice(indent.length)}`;
  });
  const text = next.join('\n');
  edit(control, start, end, text, [start, start + text.length]);
}

/** The Markdown link around a position, if there is one. */
function linkAt(value, position) {
  for (const match of value.matchAll(/(!?)\[([^\]\n]*)\]\(([^)\s]*)\)/g)) {
    const start = match.index;
    const end = start + match[0].length;
    if (!match[1] && position >= start && position <= end) return { start, end, text: match[2], href: match[3] };
  }
  return null;
}

/** The selection's text without its syntax: emphasis, code, links, images, headings, lists, quotes. */
function plain(text) {
  return text
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/<((?:https?|mailto):[^>\s]+)>/g, '$1')
    .replace(/(\*\*|__|~~|`)(.+?)\1/g, '$2')
    .replace(/(\*|_)(?=\S)(.+?)(?<=\S)\1/g, '$2')
    .replace(/^(\s*)(#{1,6}\s+|>\s?|[-+*]\s+|\d+[.)]\s+)/gm, '$1');
}

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
 * Markdown editing on the Editor's textarea. `read` turns HTML into a document within the field's
 * formats; `ask` opens the address popover; `commit` sends the change event. Returns a cleanup.
 */
export function markdownEditing({ root, control, toolbar, read, ask, commit, images }) {
  const toMarkdown = html => markdownFromDocument(read(html));
  const blocked = () => control.matches(':disabled') || control.readOnly;
  const commands = {
    bold: () => emphasize(control, '**'),
    italic: () => emphasize(control, '*'),
    bulletList: () => prefix(control, 'bulletList'),
    orderedList: () => prefix(control, 'orderedList'),
    blockquote: () => prefix(control, 'blockquote'),
    undo: () => {
      control.focus();
      document.execCommand('undo');
    },
    redo: () => {
      control.focus();
      document.execCommand('redo');
    },
    clear: () => {
      const {
        start,
        end,
        lines: current,
      } = control.selectionStart === control.selectionEnd
        ? lines(control)
        : { start: control.selectionStart, end: control.selectionEnd };
      const text = plain(current ? current.join('\n') : control.value.slice(start, end));
      edit(control, start, end, text, [start, start + text.length]);
    },
    link: button => {
      const { selectionStart: start, selectionEnd: end, value } = control;
      const link = linkAt(value, start);
      const selected = value.slice(start, end);
      ask(button, 'link', {
        current: link?.href ?? '',
        apply: href => {
          if (link) edit(control, link.start, link.end, `[${link.text}](${href})`);
          else edit(control, start, end, selected ? `[${selected}](${href})` : `<${href}>`);
        },
        remove: link && (() => edit(control, link.start, link.end, link.text)),
      });
    },
    image: button => {
      const { selectionStart: start, selectionEnd: end, value } = control;
      ask(button, 'image', { apply: href => edit(control, start, end, `![${value.slice(start, end)}](${href})`) });
    },
  };
  const refresh = () => {
    for (const button of toolbar.querySelectorAll('[data-fruit-command]')) {
      const command = button.dataset.fruitCommand;
      button.disabled = blocked() || !commands[command];
      button.removeAttribute('aria-pressed');
      if (['link', 'image'].includes(command)) button.setAttribute('aria-haspopup', 'dialog');
    }
  };
  const allowed = command => !!toolbar.querySelector(`[data-fruit-command="${command}"]:not(:disabled)`);
  const click = event => {
    const button = event.target.closest('[data-fruit-command]');
    if (!button || button.disabled || !commands[button.dataset.fruitCommand]) return;
    commands[button.dataset.fruitCommand](button);
  };
  /** Images go to the application, which uploads them and inserts their Markdown. */
  const upload = (files, position) => {
    const found = [...(files ?? [])].filter(file => file.type.startsWith('image/'));
    if (!found.length || !images) return false;
    control.dispatchEvent(
      new CustomEvent('fruit-editor-upload', {
        bubbles: true,
        cancelable: true,
        detail: {
          files: found,
          insert: (src, alt = '') => {
            const at = Math.min(position, control.value.length);
            edit(control, at, at, `![${alt}](${src})`);
            commit();
          },
        },
      }),
    );
    return true;
  };
  const keydown = event => {
    if (blocked() || event.isComposing) return;
    const key = event.key.toLowerCase();
    const mod = (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey;
    if (mod && (key === 'b' || key === 'i') && allowed(key === 'b' ? 'bold' : 'italic')) {
      event.preventDefault();
      commands[key === 'b' ? 'bold' : 'italic']();
      return;
    }
    if (event.key !== 'Enter' || event.shiftKey || event.altKey) return;
    const submit = root.dataset.fruitEnter === 'submit' && control.form;
    if (event.ctrlKey || event.metaKey) {
      if (!submit) return;
      event.preventDefault();
      control.form.requestSubmit();
      return;
    }
    // In a list or quote, Enter starts the next item; on an empty item, it ends the list.
    const { selectionStart: start, selectionEnd: end, value } = control;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const lineEnd = value.indexOf('\n', start) < 0 ? value.length : value.indexOf('\n', start);
    const line = value.slice(lineStart, lineEnd);
    const item = start === end && (list.exec(line) || quote.exec(line));
    if (item) {
      event.preventDefault();
      const marker = item[0];
      if (!line.slice(marker.length).trim() && start === lineEnd) {
        edit(control, lineStart, lineEnd, item[1]);
        return;
      }
      const next = item[3] ? marker.replace(item[3], String(Number(item[3]) + 1)) : marker;
      edit(control, start, end, `\n${next}`);
      return;
    }
    if (submit) {
      event.preventDefault();
      control.form.requestSubmit();
    }
  };
  const paste = event => {
    if (upload(event.clipboardData?.files, control.selectionStart)) {
      event.preventDefault();
      return;
    }
    if (root.dataset.fruitPaste === 'plain') return;
    const html = event.clipboardData?.getData('text/html');
    const markdown = html && toMarkdown(html);
    if (!markdown) return;
    event.preventDefault();
    edit(control, control.selectionStart, control.selectionEnd, markdown);
  };
  const drop = event => {
    if (!upload(event.dataTransfer?.files, control.selectionStart)) return;
    event.preventDefault();
  };
  control.addEventListener('keydown', keydown);
  control.addEventListener('paste', paste);
  control.addEventListener('drop', drop);
  toolbar.addEventListener('click', click);
  const observer = new MutationObserver(refresh);
  observer.observe(control, { attributes: true, attributeFilter: ['disabled', 'readonly'] });
  refresh();
  return {
    /** Content requests: Markdown as it is, or HTML read into the field's formats. */
    insert: ({ markdown, html }) => {
      const text = markdown ?? (html ? toMarkdown(html) : '');
      edit(control, control.selectionStart, control.selectionEnd, text);
    },
    // Replacing it all doesn't take focus: a draft or template can arrive from the server.
    set: ({ markdown, html }) => publishValue(control, markdown ?? (html ? toMarkdown(html) : '')),
    destroy() {
      observer.disconnect();
      control.removeEventListener('keydown', keydown);
      control.removeEventListener('paste', paste);
      control.removeEventListener('drop', drop);
      toolbar.removeEventListener('click', click);
    },
  };
}

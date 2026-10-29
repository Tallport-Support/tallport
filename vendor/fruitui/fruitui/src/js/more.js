/**
 * The content's lines of text, top to bottom, unscrolled and relative to its top. Lines are counted
 * from the text itself, so the gap between two paragraphs or list items is never a line.
 */
function textLines(content) {
  const origin = content.getBoundingClientRect().top - content.scrollTop;
  const walker = content.ownerDocument.createTreeWalker(content, NodeFilter.SHOW_TEXT);
  const range = content.ownerDocument.createRange();
  const rects = [];
  while (walker.nextNode()) {
    range.selectNodeContents(walker.currentNode);
    for (const rect of range.getClientRects())
      if (rect.width && rect.height) rects.push({ top: rect.top - origin, bottom: rect.bottom - origin });
  }
  rects.sort((a, b) => a.top - b.top);
  const lines = [];
  for (const rect of rects) {
    const last = lines.at(-1);
    // Text on the same line (a link, bold) overlaps it vertically.
    if (last && rect.top < last.bottom - 1) last.bottom = Math.max(last.bottom, rect.bottom);
    else lines.push(rect);
  }
  return lines;
}

/**
 * Long content shown as its first few lines of text (x-fruit::more). `overflowing` turns true while
 * more than two lines of text follow the first `--f-more-lines` (4), or other content (a picture)
 * makes it more than four lines taller, so Show More never hides just a line or two; `height` is
 * where the last line shown ends, and `showingAll` is true while the reader has asked for the rest.
 * The content is measured again as its width or the content itself changes (a Livewire morph, a
 * list rendered from script).
 */
export function fruitMore() {
  let resize, mutation, frame;
  return {
    overflowing: false,
    showingAll: false,
    height: 0,
    init() {
      const root = this.$el;
      const content = root.querySelector(':scope > .f-more__content');
      if (!content) return;
      const measure = () => {
        const style = getComputedStyle(content);
        const line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
        const shown = parseFloat(getComputedStyle(root).getPropertyValue('--f-more-lines')) || 4;
        const lines = textLines(content);
        this.height = Math.ceil(lines[shown - 1]?.bottom ?? shown * line);
        this.overflowing = lines.length > shown + 2 || content.scrollHeight - this.height > 4 * line;
      };
      // A frame later: clamping changes the content's size, which would notify the observer again at once.
      const schedule = () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(measure);
      };
      resize = new ResizeObserver(schedule);
      resize.observe(content);
      mutation = new MutationObserver(schedule);
      mutation.observe(content, { childList: true, subtree: true, characterData: true });
      // Tabbing to a link the clamp hides shows the rest, rather than scrolling inside the clamp.
      content.addEventListener('focusin', event => {
        if (!this.overflowing || this.showingAll) return;
        // Where it sits unscrolled: focusing it may already have scrolled the clamp to it.
        const bottom = event.target.getBoundingClientRect().bottom + content.scrollTop;
        if (bottom > content.getBoundingClientRect().bottom) {
          this.showingAll = true;
          content.scrollTop = 0;
        }
      });
      measure();
    },
    toggle() {
      this.showingAll = !this.showingAll;
      // Showing less moves the button up; keep it in view where it went.
      if (!this.showingAll)
        this.$nextTick(() => this.$el.querySelector(':scope > .f-more__toggle')?.scrollIntoView({ block: 'nearest' }));
    },
    destroy() {
      cancelAnimationFrame(frame);
      resize?.disconnect();
      mutation?.disconnect();
    },
  };
}

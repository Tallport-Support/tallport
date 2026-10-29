/**
 * Long content shown as its first few lines (x-fruit::more). `overflowing` turns true while the
 * content is more than two lines taller than `--f-more-lines` (4), so Show More never hides just a
 * line or two; `showingAll` while the reader has asked for the rest. The content is measured again
 * as its width or the content itself changes (a Livewire morph, a list rendered from script).
 */
export function fruitMore() {
  let resize, mutation, frame;
  return {
    overflowing: false,
    showingAll: false,
    init() {
      const root = this.$el;
      const content = root.querySelector(':scope > .f-more__content');
      if (!content) return;
      const measure = () => {
        const style = getComputedStyle(content);
        const line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
        const lines = parseFloat(getComputedStyle(root).getPropertyValue('--f-more-lines')) || 4;
        this.overflowing = content.scrollHeight > (lines + 2) * line;
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

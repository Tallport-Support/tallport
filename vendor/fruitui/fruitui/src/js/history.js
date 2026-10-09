/**
 * A message history read from the bottom up: it opens at the newest (last) message and stays there
 * as messages arrive, images load or a Livewire morph changes the content, unless the reader (or the
 * application, scrolling a message into view) has moved away from it. Then `awayFromLatest` turns
 * true past `threshold` pixels, for a Jump to Latest button, and new content leaves the reading
 * position alone. Sending from a form in the same pane, such as its composer, returns to the newest.
 */
export function fruitHistory({ threshold = 160 } = {}) {
  let scroller, pane, resize, mutation, track, submit, stick;
  // Following the newest message, and the scroll position this helper last saw.
  let following = true;
  let top = 0;
  let preserving = false;
  const distance = () => scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;
  return {
    awayFromLatest: false,
    init() {
      scroller = this.$el;
      stick = () => {
        if (preserving) return;
        // A scroll not seen yet (scrollIntoView, then growth in the same frame) decides first.
        if (scroller.scrollTop !== top) following = distance() < 24;
        if (following) scroller.scrollTop = scroller.scrollHeight;
        top = scroller.scrollTop;
      };
      track = () => {
        // The event for this helper's own scroll can arrive after new content; it moved nothing.
        if (scroller.scrollTop === top) return;
        following = distance() < 24;
        this.awayFromLatest = distance() > threshold;
        top = scroller.scrollTop;
      };
      submit = () => this.jumpToLatest({ smooth: false });
      resize = new ResizeObserver(stick);
      resize.observe(scroller);
      for (const child of scroller.children) resize.observe(child);
      mutation = new MutationObserver(records => {
        for (const record of records)
          for (const node of record.addedNodes) if (node.nodeType === 1) resize.observe(node);
        stick();
      });
      mutation.observe(scroller, { childList: true });
      scroller.addEventListener('scroll', track, { passive: true });
      pane = scroller.parentElement?.closest('.f-pane') ?? scroller.parentElement;
      pane?.addEventListener('submit', submit);
      stick();
    },
    /** Keep the first visible anchored message in place while an action replaces a bounded window. */
    async preservePosition(action) {
      const viewport = scroller.getBoundingClientRect();
      const anchor = [...scroller.querySelectorAll('[data-fruit-history-anchor]')].find(
        element => element.getBoundingClientRect().bottom > viewport.top,
      );
      const key = anchor?.getAttribute('data-fruit-history-anchor');
      const offset = anchor?.getBoundingClientRect().top - viewport.top;
      following = false;
      preserving = true;
      try {
        return await action();
      } finally {
        await new Promise(resolve => requestAnimationFrame(resolve));
        if (key !== undefined && key !== null) {
          const current = [...scroller.querySelectorAll('[data-fruit-history-anchor]')].find(
            element => element.getAttribute('data-fruit-history-anchor') === key,
          );
          if (current) scroller.scrollTop += current.getBoundingClientRect().top - viewport.top - offset;
        }
        preserving = false;
        top = scroller.scrollTop;
        this.awayFromLatest = distance() > threshold;
      }
    },
    /** Let a host load its actual latest page before this control scrolls there. */
    requestLatest() {
      if (scroller.dispatchEvent(new CustomEvent('fruit-history-latest', { bubbles: true, cancelable: true }))) {
        this.jumpToLatest({ focus: true });
      }
    },
    /** Return to the newest message; with focus, move focus there too (the button that asked hides). */
    jumpToLatest({ focus = false, smooth = true } = {}) {
      following = true;
      this.awayFromLatest = false;
      const motion = smooth && !matchMedia('(prefers-reduced-motion: reduce)').matches;
      scroller.scrollTo({ top: scroller.scrollHeight, behavior: motion ? 'smooth' : 'instant' });
      top = scroller.scrollTop;
      if (focus) ([...scroller.querySelectorAll('[tabindex="-1"]')].at(-1) ?? scroller).focus({ preventScroll: true });
    },
    destroy() {
      resize?.disconnect();
      mutation?.disconnect();
      scroller?.removeEventListener('scroll', track);
      pane?.removeEventListener('submit', submit);
    },
  };
}

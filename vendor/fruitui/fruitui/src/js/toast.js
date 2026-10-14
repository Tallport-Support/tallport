const TONES = ['neutral', 'success', 'danger'];

/**
 * Announce a message on the page's toast outlet (x-fruit::toaster or an element with x-data="fruitToast").
 * `tone` is neutral (default), success or danger; danger toasts are announced assertively and stay longer.
 */
export function toast(message, { tone = 'neutral' } = {}) {
  if (!TONES.includes(tone)) throw new Error(`FruitUI toast tone must be one of: ${TONES.join(', ')}.`);
  window.dispatchEvent(new CustomEvent('fruit-toast', { detail: { message, tone } }));
}

/**
 * The toast outlet: shows one message at a time from fruit-toast events, pauses while hovered or
 * focused, and uses the top layer where supported so open modal dialogs do not cover it. A click on it
 * closes it, and so does Escape that nothing else took, so it never keeps covering what's beneath.
 */
export function fruitToast({ duration = 4000, message = null, tone = 'neutral', action = false } = {}) {
  if (!Number.isFinite(duration) || duration < 0)
    throw new Error('FruitUI Toast duration must be a nonnegative number of milliseconds.');
  let timer,
    started,
    remaining = duration;
  const controller = new AbortController();
  const clear = () => {
    clearTimeout(timer);
    timer = undefined;
  };
  // Errors stay twice as long; 0 still keeps every toast until it is replaced.
  const lifetime = tone => (tone === 'danger' ? duration * 2 : duration);
  return {
    notice: '',
    showAction: false,
    init() {
      const options = { signal: controller.signal };
      if (this.$el.classList.contains('f-toast') && typeof this.$el.showPopover === 'function')
        this.$el.popover = 'manual';
      this.$el.setAttribute('aria-live', 'polite');
      window.addEventListener(
        'fruit-toast',
        event => this.notify(event.detail?.message ?? '', event.detail?.tone),
        options,
      );
      for (const name of ['mouseenter', 'focusin']) this.$el.addEventListener(name, () => this.pauseNotice(), options);
      for (const name of ['mouseleave', 'focusout'])
        this.$el.addEventListener(name, () => this.resumeNotice(), options);
      // A click anywhere but its action, unless it selected text to copy.
      this.$el.addEventListener(
        'click',
        event => {
          if (event.target.closest('.f-toast__action, a, button, input, select, textarea')) return;
          if (String(window.getSelection?.() ?? '')) return;
          this.dismissNotice();
        },
        options,
      );
      // Escape that a menu, picker or dialog didn't take: keyboard focus is never left under it.
      window.addEventListener(
        'keydown',
        event => {
          if (event.key !== 'Escape' || event.defaultPrevented || !this.notice) return;
          if (!this.$el.contains(event.target) && event.target.closest?.('dialog[open], :popover-open')) return;
          event.preventDefault();
          this.dismissNotice();
        },
        options,
      );
      if (message) this.notify(message, tone, action);
    },
    notify(text, tone = 'neutral', action = false) {
      clear();
      if (!TONES.includes(tone)) tone = 'neutral';
      // The live region's politeness changes before its text, so screen readers announce errors at once.
      this.$el.setAttribute('aria-live', tone === 'danger' ? 'assertive' : 'polite');
      if (tone === 'neutral') delete this.$el.dataset.tone;
      else this.$el.dataset.tone = tone;
      this.notice = String(text);
      this.showAction = Boolean(action && this.notice);
      remaining = lifetime(tone);
      if (this.$el.popover) {
        // Reopen so the toast stacks above anything that entered the top layer since.
        if (this.$el.matches(':popover-open')) this.$el.hidePopover();
        this.$el.showPopover();
      }
      this.resumeNotice();
    },
    dismissNotice() {
      clear();
      this.notice = '';
      this.showAction = false;
      remaining = 0;
      if (this.$el.popover && this.$el.matches(':popover-open')) this.$el.hidePopover();
    },
    pauseNotice() {
      if (timer !== undefined) {
        remaining = Math.max(0, remaining - (performance.now() - started));
        clear();
      }
    },
    resumeNotice() {
      if (!this.notice || !duration || timer !== undefined) return;
      started = performance.now();
      timer = setTimeout(() => this.dismissNotice(), remaining);
    },
    destroy() {
      clear();
      controller.abort();
    },
  };
}

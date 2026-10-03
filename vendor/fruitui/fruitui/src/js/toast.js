/** Announce a message on the page's toast outlet (x-fruit::toaster or an element with x-data="fruitToast"). */
export function toast(message) {
  window.dispatchEvent(new CustomEvent('fruit-toast', { detail: { message } }));
}

/**
 * The toast outlet: shows one message at a time from fruit-toast events, pauses while hovered or
 * focused, and uses the top layer where supported so open modal dialogs do not cover it.
 */
export function fruitToast({ duration = 4000, message = null } = {}) {
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
  return {
    notice: '',
    init() {
      const options = { signal: controller.signal };
      if (this.$el.classList.contains('f-toast') && typeof this.$el.showPopover === 'function')
        this.$el.popover = 'manual';
      window.addEventListener('fruit-toast', event => this.notify(event.detail?.message ?? ''), options);
      for (const name of ['mouseenter', 'focusin']) this.$el.addEventListener(name, () => this.pauseNotice(), options);
      for (const name of ['mouseleave', 'focusout'])
        this.$el.addEventListener(name, () => this.resumeNotice(), options);
      if (message) this.notify(message);
    },
    notify(text) {
      clear();
      this.notice = String(text);
      remaining = duration;
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

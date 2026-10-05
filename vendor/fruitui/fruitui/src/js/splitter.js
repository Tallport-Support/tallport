import { isRtl } from './popup.js';
import { fruitMessage } from './messages.js';

/**
 * A bounded, vertical window splitter. It starts from the pane's rendered width, so a width the
 * server renders (style="--f-list-width: 412px" on the workspace) holds. Each committed resize (a
 * drag's end, keyboard steps after a pause, a double-click reset to the default) sends a bubbling
 * fruit-resize event with { pane, variable, value } in pixels; persistence belongs to the app.
 */
export function fruitSplitter({ pane, variable, min = 160, max = 420, reserve = 280, flexible, edge = 'end' }) {
  if (
    !pane ||
    !flexible ||
    pane === flexible ||
    !/^--f-[\w-]+$/.test(variable) ||
    !['start', 'end'].includes(edge) ||
    ![min, max, reserve].every(Number.isFinite) ||
    min <= 0 ||
    max < min ||
    reserve <= 0
  ) {
    throw new Error(
      'FruitUI splitter requires pane/flexible IDs, a --f- variable, positive bounds and start/end edge.',
    );
  }
  let handle, frame, primary, remaining, observer, mutation, animation, drag, handlers, pending;
  const visible = element => !!element?.getClientRects().length && getComputedStyle(element).display !== 'none';
  return {
    init() {
      handle = this.$el;
      frame = handle.closest('.f-workspace');
      primary = frame?.querySelector(`[id="${CSS.escape(pane)}"]`);
      remaining = frame?.querySelector(`[id="${CSS.escape(flexible)}"]`);
      if (!primary || !remaining) throw new Error('FruitUI splitter panes must belong to its workspace.');
      handle.setAttribute('data-ready', '');
      handle.setAttribute('data-edge', edge);
      handle.setAttribute('aria-controls', pane);
      handlers = Object.fromEntries(
        Object.entries({
          pointerdown: this.start,
          pointermove: this.move,
          pointerup: this.end,
          pointercancel: this.cancel,
          lostpointercapture: this.end,
          keydown: this.key,
          dblclick: this.reset,
          blur: this.forget,
        }).map(([event, handler]) => [event, handler.bind(this)]),
      );
      for (const [event, handler] of Object.entries(handlers)) handle.addEventListener(event, handler);
      // ARIA values change no layout, so they update at once; clamping waits for the next frame.
      observer = new ResizeObserver(() => {
        this.describe();
        this.schedule();
      });
      observer.observe(frame);
      observer.observe(primary);
      observer.observe(remaining);
      mutation = new MutationObserver(() => this.schedule());
      mutation.observe(frame, { attributes: true });
      this.describe();
      this.schedule();
    },
    bounds() {
      const width = primary.getBoundingClientRect().width;
      const available = width + remaining.getBoundingClientRect().width - reserve;
      // Preserve the content pane's minimum before expanding a secondary pane.
      const upper = Math.max(min, Math.floor(Math.min(max, available)));
      return { width, upper };
    },
    schedule() {
      cancelAnimationFrame(animation);
      animation = requestAnimationFrame(() => this.update());
    },
    update() {
      if (!visible(handle) || !visible(primary) || !visible(remaining)) {
        if (drag) this.cancel();
        return;
      }
      const { width, upper } = this.bounds();
      if (width > upper + 1 || width < min - 1) this.set(width);
      this.describe();
    },
    describe() {
      if (!visible(primary) || !visible(remaining)) return;
      const { width, upper } = this.bounds();
      handle.setAttribute('aria-valuemin', Math.round(min));
      handle.setAttribute('aria-valuemax', Math.floor(upper));
      handle.setAttribute('aria-valuenow', Math.round(width));
      handle.setAttribute(
        'aria-valuetext',
        fruitMessage(handle, 'value-text', '{count} pixels', { count: Math.round(width) }),
      );
    },
    set(width) {
      const { upper } = this.bounds();
      const value = `${Math.round(Math.max(min, Math.min(upper, width)))}px`;
      if (frame.style.getPropertyValue(variable) !== value) {
        frame.style.setProperty(variable, value);
        this.schedule();
      }
    },
    start(event) {
      if (event.button !== 0 || !visible(primary) || !visible(remaining)) return;
      event.preventDefault();
      // Focus stays for Escape and arrow keys, without the keyboard focus line a pointer doesn't need.
      handle.setAttribute('data-pointer', '');
      handle.focus({ preventScroll: true });
      drag = {
        id: event.pointerId,
        x: event.clientX,
        width: primary.getBoundingClientRect().width,
        previous: frame.style.getPropertyValue(variable),
      };
      handle.setPointerCapture(event.pointerId);
      handle.setAttribute('data-resizing', '');
      frame.setAttribute('data-resizing', '');
    },
    move(event) {
      if (!drag || event.pointerId !== drag.id) return;
      const direction = (isRtl(frame) ? -1 : 1) * (edge === 'start' ? -1 : 1);
      this.set(drag.width + (event.clientX - drag.x) * direction);
    },
    end(commit = true) {
      if (!drag) return;
      const { id, width } = drag;
      drag = undefined;
      handle.removeAttribute('data-resizing');
      frame.removeAttribute('data-resizing');
      if (handle.hasPointerCapture(id)) handle.releasePointerCapture(id);
      if (commit && Math.abs(primary.getBoundingClientRect().width - width) >= 1) this.commit();
    },
    cancel() {
      if (!drag) return;
      if (drag.previous) frame.style.setProperty(variable, drag.previous);
      else frame.style.removeProperty(variable);
      this.end(false);
      this.schedule();
    },
    /** Report the committed width, at once or after keyboard steps pause. */
    commit(delay = 0) {
      clearTimeout(pending);
      pending = setTimeout(() => {
        if (!visible(primary)) return;
        handle.dispatchEvent(
          new CustomEvent('fruit-resize', {
            bubbles: true,
            detail: { pane, variable, value: Math.round(primary.getBoundingClientRect().width) },
          }),
        );
      }, delay);
    },
    key(event) {
      if (event.key !== 'Escape') this.forget();
      if (event.key === 'Escape' && drag) {
        event.preventDefault();
        event.stopPropagation();
        this.cancel();
        return;
      }
      const { width, upper } = this.bounds();
      const direction = (isRtl(frame) ? -1 : 1) * (edge === 'start' ? -1 : 1);
      const step = event.shiftKey ? 32 : 8;
      const values = {
        ArrowLeft: width - step * direction,
        ArrowRight: width + step * direction,
        Home: min,
        End: upper,
      };
      if (!(event.key in values)) return;
      event.preventDefault();
      event.stopPropagation();
      this.set(values[event.key]);
      this.commit(400);
    },
    forget() {
      handle.removeAttribute('data-pointer');
    },
    /** Back to the stylesheet's width: drop the custom value, then report the result. */
    reset() {
      frame.style.removeProperty(variable);
      this.schedule();
      requestAnimationFrame(() => this.commit());
    },
    destroy() {
      clearTimeout(pending);
      this.end(false);
      observer?.disconnect();
      mutation?.disconnect();
      cancelAnimationFrame(animation);
      for (const [event, handler] of Object.entries(handlers)) handle.removeEventListener(event, handler);
    },
  };
}

import { isRtl } from './popup.js';
import { fruitMessage } from './messages.js';

/** A bounded, vertical window splitter. No application navigation or persistence. */
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
  let handle, frame, primary, remaining, observer, mutation, animation, drag, initial, handlers;
  const visible = element => !!element?.getClientRects().length && getComputedStyle(element).display !== 'none';
  return {
    init() {
      handle = this.$el;
      frame = handle.closest('.f-workspace');
      primary = frame?.querySelector(`[id="${CSS.escape(pane)}"]`);
      remaining = frame?.querySelector(`[id="${CSS.escape(flexible)}"]`);
      if (!primary || !remaining) throw new Error('FruitUI splitter panes must belong to its workspace.');
      initial = visible(primary) ? primary.getBoundingClientRect().width : undefined;
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
      initial ??= width;
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
    end() {
      if (!drag) return;
      const id = drag.id;
      drag = undefined;
      handle.removeAttribute('data-resizing');
      frame.removeAttribute('data-resizing');
      if (handle.hasPointerCapture(id)) handle.releasePointerCapture(id);
    },
    cancel() {
      if (!drag) return;
      if (drag.previous) frame.style.setProperty(variable, drag.previous);
      else frame.style.removeProperty(variable);
      this.end();
      this.schedule();
    },
    key(event) {
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
    },
    reset() {
      this.set(initial);
    },
    destroy() {
      this.end();
      observer?.disconnect();
      mutation?.disconnect();
      cancelAnimationFrame(animation);
      for (const [event, handler] of Object.entries(handlers)) handle.removeEventListener(event, handler);
    },
  };
}

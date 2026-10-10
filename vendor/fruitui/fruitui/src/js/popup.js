/** Whether an element lays out right to left. */
export const isRtl = element => getComputedStyle(element).direction === 'rtl';

/** Use the browser top layer to avoid clipping by scrollable panes/dialogs. */
export function fruitPopup(popup, anchor, { stretch = false, above = false, point = null, start = false } = {}) {
  const supported = typeof popup.showPopover === 'function';
  const originalStyle = popup.getAttribute('style');
  let showing = false;
  if (supported) popup.popover = 'manual';
  const position = () => {
    if (!showing || !supported) return;
    const rect = anchor.getBoundingClientRect(),
      viewport = window.visualViewport;
    const left = viewport?.offsetLeft || 0,
      top = viewport?.offsetTop || 0;
    const width = viewport?.width || window.innerWidth,
      height = viewport?.height || window.innerHeight;
    popup.style.position = 'fixed';
    popup.style.inset = 'auto';
    popup.style.margin = '0';
    popup.style.transform = 'none';
    popup.style.maxWidth = `${Math.max(0, width - 16)}px`;
    popup.style.maxHeight = `${Math.max(40, height - 16)}px`;
    popup.style.overflowY = 'auto';
    if (stretch) popup.style.width = `${Math.min(rect.width, width - 16)}px`;
    const size = popup.getBoundingClientRect();
    // A point anchor (a context menu) opens toward the inline end from the pointer.
    const aligned = point
      ? isRtl(point)
        ? rect.left - size.width
        : rect.left
      : stretch || isRtl(anchor) !== start
        ? rect.left
        : rect.right - size.width;
    // A panel that would cross the viewport's edge lines up with the trigger's other side instead, when
    // it fits there: a button at the start of a sidebar's footer opens toward the end, beside it.
    const fits = value => value >= left + 8 && value + size.width <= left + width - 8;
    const x =
      point || stretch || fits(aligned) ? aligned : ([rect.left, rect.right - size.width].find(fits) ?? aligned);
    const below = top + height - rect.bottom - 8,
      before = rect.top - top - 8;
    const placeAbove = (above && before >= size.height) || (below < size.height && before > below);
    const available = placeAbove ? before : below;
    popup.style.maxHeight = `${Math.max(40, available)}px`;
    const actualHeight = popup.getBoundingClientRect().height;
    popup.style.left = `${Math.max(left + 8, Math.min(x, left + width - size.width - 8))}px`;
    popup.style.top = `${Math.max(top + 8, Math.min(placeAbove ? rect.top - actualHeight - 4 : rect.bottom + 4, top + height - actualHeight - 8))}px`;
  };
  const show = () => {
    showing = true;
    if (supported) popup.popover = 'manual';
    if (supported && !popup.matches(':popover-open')) popup.showPopover();
    position();
  };
  const hide = () => {
    showing = false;
    if (supported && popup.matches(':popover-open')) popup.hidePopover();
  };
  window.addEventListener('resize', position);
  document.addEventListener('scroll', position, true);
  window.visualViewport?.addEventListener('resize', position);
  window.visualViewport?.addEventListener('scroll', position);
  return {
    show,
    hide,
    destroy() {
      hide();
      window.removeEventListener('resize', position);
      document.removeEventListener('scroll', position, true);
      window.visualViewport?.removeEventListener('resize', position);
      window.visualViewport?.removeEventListener('scroll', position);
      if (supported) popup.removeAttribute('popover');
      if (originalStyle === null) popup.removeAttribute('style');
      else popup.setAttribute('style', originalStyle);
    },
  };
}

/**
 * A details element whose panel floats in the top layer: it opens with the native summary and
 * closes on an outside pointer (focus stays) or Escape (focus returns to the summary).
 */
export function fruitDetailsPopup(
  details,
  panel,
  { above = false, owns = node => node?.closest('details') === details, onToggle } = {},
) {
  const trigger = details.querySelector('summary');
  const overlay = fruitPopup(panel, trigger, { above });
  const controller = new AbortController();
  const options = { signal: controller.signal };
  const close = restoreFocus => {
    details.open = false;
    overlay.hide();
    if (restoreFocus) trigger.focus();
  };
  details.addEventListener(
    'toggle',
    event => {
      if (event.target !== details) return;
      if (details.open) overlay.show();
      else overlay.hide();
      onToggle?.(details.open);
    },
    options,
  );
  document.addEventListener(
    'pointerdown',
    event => {
      if (details.open && !details.contains(event.target)) close(false);
    },
    options,
  );
  details.addEventListener(
    'keydown',
    event => {
      if (event.key === 'Escape' && details.open && owns(event.target)) {
        event.preventDefault();
        event.stopPropagation();
        close(true);
      }
    },
    options,
  );
  if (details.open) overlay.show();
  return {
    trigger,
    show: () => overlay.show(),
    close,
    destroy() {
      controller.abort();
      overlay.destroy();
    },
  };
}

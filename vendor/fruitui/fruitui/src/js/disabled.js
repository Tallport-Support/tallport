const DISABLED = ':is(.f-button, .f-menu-item, .f-tab, summary)[aria-disabled="true"]';

/**
 * A control that can't take disabled (a link styled as a button or menu item, a menu's or
 * disclosure's summary) is disabled with aria-disabled="true": it stays focusable, so it can be
 * found, and ignores activation by pointer or keyboard, as a disabled button does. Capturing at the
 * document runs before the control's own handlers and stops a link or a summary's toggle.
 */
export function listenForDisabledControls(target = document) {
  const block = event => {
    if (!event.target.closest?.(DISABLED)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  };
  target.addEventListener('click', block, true);
  return () => target.removeEventListener('click', block, true);
}

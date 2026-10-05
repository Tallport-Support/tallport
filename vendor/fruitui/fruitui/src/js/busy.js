const BUSY = '.f-button:is([aria-busy="true"], [data-loading])';

/**
 * A busy button ignores further clicks, by pointer or keyboard, so an action cannot run twice.
 * It keeps focus, unlike native disabled. Capturing at the document runs before the button's own
 * handlers, including Livewire's wire:click and a submit button's form submission.
 */
export function listenForBusyButtons(target = document) {
  const block = event => {
    if (!event.target.closest?.(BUSY)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  };
  target.addEventListener('click', block, true);
  return () => target.removeEventListener('click', block, true);
}

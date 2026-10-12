const visible = element => Boolean(element?.isConnected && element.getClientRects().length);

/** The rows leading to the open levels, and the levels themselves: what navigating changes. */
const openPath = root =>
  [...root.querySelectorAll('a[aria-current]:not([aria-current="false"])')].map(row => row.getAttribute('href'));
const levels = root =>
  [...root.querySelectorAll('.f-drilldown__column')].map(
    level => `${level.getAttribute('aria-labelledby')} ${level.classList.contains('f-drilldown__column--optional')}`,
  );

/**
 * Each level shows the row that leads to the next one: a current row scrolled out of its level's view
 * (a long list after a page load) comes to the middle of the level. Only the level scrolls, not the page.
 */
const revealCurrentRows = root => {
  for (const level of root.querySelectorAll('.f-drilldown__column')) {
    const row = level.querySelector('a[aria-current]:not([aria-current="false"])');
    const scroller = row?.closest('.f-pane__scroll, .f-pane--scroll');
    if (!scroller || !level.contains(scroller) || !visible(row)) continue;
    const view = scroller.getBoundingClientRect();
    const box = row.getBoundingClientRect();
    if (box.top >= view.top && box.bottom <= view.bottom) continue;
    scroller.scrollTop += box.top + box.height / 2 - (view.top + view.height / 2);
  }
};

/**
 * Keeps each level's current row in view, also after a page load, and focus in view as a
 * Drill-Down's levels change, whoever changes them (Alpine, a Livewire morph). The row that opened a
 * level keeps focus while it's still shown. Where focus went out of view, it moves to the deepest
 * level's title; going back, to the row that leads where you were. Other updates, such as a morph
 * after a field's change, leave focus alone.
 */
export function fruitDrilldown() {
  let observer, frame;
  return {
    init() {
      const root = this.$el;
      let path = openPath(root);
      let state = JSON.stringify([path, levels(root)]);
      const settle = () => {
        frame = null;
        const from = path;
        path = openPath(root);
        const next = JSON.stringify([path, levels(root)]);
        if (next === state) return;
        state = next;
        revealCurrentRows(root);
        const active = document.activeElement;
        if (active && active !== document.body && (!root.contains(active) || visible(active))) return;
        // Back: the open path got shorter along the same way.
        const back = path.length < from.length && path.every((href, index) => href === from[index]);
        const row = [...root.querySelectorAll('.f-drilldown__column a[href]')]
          .filter(link => visible(link) && !link.closest('.f-drilldown__header'))
          .filter(link => from.includes(link.getAttribute('href')))
          .at(-1);
        const title = [...root.querySelectorAll('.f-drilldown__title')].filter(visible).at(-1);
        (back ? (row ?? title) : (title ?? row))?.focus({ preventScroll: true });
      };
      requestAnimationFrame(() => revealCurrentRows(root));
      observer = new MutationObserver(() => {
        frame ??= requestAnimationFrame(settle);
      });
      observer.observe(root, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['aria-current', 'class'],
      });
    },
    destroy() {
      observer?.disconnect();
      cancelAnimationFrame(frame);
    },
  };
}

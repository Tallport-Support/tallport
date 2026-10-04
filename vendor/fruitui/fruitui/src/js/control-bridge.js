let sequence = 0;
export const fruitId = prefix => `${prefix}-${++sequence}`;

/**
 * Give a server-rendered element a generated id that survives Livewire updates. Livewire's morph
 * matches elements by id, so an id only the browser knows would make it replace the element; an
 * id recorded as an Alpine binding is carried over to the re-rendered element instead.
 */
export function keepId(element, prefix) {
  if (!element.id) {
    element.id = fruitId(prefix);
    element._x_bindings = { ...element._x_bindings, id: element.id };
  }
  return element.id;
}

/** Discrete selections commit immediately; document editing commits on blur. */
export function publishValue(control, value, { commit = true } = {}) {
  if (control.value === value) return;
  control.value = value;
  control.dispatchEvent(new Event('input', { bubbles: true }));
  if (commit) control.dispatchEvent(new Event('change', { bubbles: true }));
}

/** The native named control remains the form/model owner. */
export function bridgeControl(component, control, query, sync, { presentation = query, focusRoot = query } = {}) {
  const cleanups = [];
  const listen = (node, event, handler) => {
    node?.addEventListener(event, handler);
    cleanups.push(() => node?.removeEventListener(event, handler));
  };
  const labels = () => [...(control.labels || [])];
  const originalClass = presentation.className,
    originalStyle = presentation.getAttribute('style'),
    originalPlaceholder = query.placeholder || '';
  const wasFocused = control.ownerDocument.activeElement === control;
  let value = control.value;
  listen(control.ownerDocument, 'click', event => {
    if (
      labels().some(label => label.contains(event.target)) &&
      (event.target === control || !event.target.closest('button, a, input, select, textarea'))
    ) {
      event.preventDefault();
      query.focus();
    }
  });
  const attributes = (reason = 'attributes') => {
    if (!control.hidden) control.hidden = true;
    const currentLabels = labels();
    for (const label of currentLabels) keepId(label, 'fruit-label');
    for (const name of [
      'aria-label',
      'aria-labelledby',
      'aria-describedby',
      'aria-invalid',
      'aria-errormessage',
      'dir',
      'lang',
      'title',
      'spellcheck',
      'inputmode',
      'autocapitalize',
    ]) {
      if (control.hasAttribute(name)) query.setAttribute(name, control.getAttribute(name));
      else query.removeAttribute(name);
    }
    if (!query.hasAttribute('aria-label') && !query.hasAttribute('aria-labelledby') && currentLabels.length) {
      query.setAttribute('aria-labelledby', currentLabels.map(label => label.id).join(' '));
    }
    query.setAttribute('aria-required', String(control.required));
    query.tabIndex = control.matches(':disabled') ? -1 : control.tabIndex;
    if ('disabled' in query) query.disabled = control.matches(':disabled');
    if ('readOnly' in query) query.readOnly = control.readOnly || false;
    // Presentation follows the fallback control; identity, name, models and actions stay native.
    presentation.className = [
      ...new Set(
        `${originalClass} ${control.className
          .split(/\s+/)
          .filter(name => name !== 'f-input' || presentation === query)
          .join(' ')}`
          .split(/\s+/)
          .filter(Boolean),
      ),
    ].join(' ');
    const style = control.getAttribute('style') || originalStyle;
    if (style === null) presentation.removeAttribute('style');
    else presentation.setAttribute('style', style);
    if ('placeholder' in query) query.placeholder = control.getAttribute('placeholder') ?? originalPlaceholder;
    sync(reason);
    value = control.value;
  };
  const valueChanged = () => {
    if (control.value === value) return;
    query.setCustomValidity?.('');
    attributes('value');
  };
  listen(control, 'input', valueChanged);
  listen(control, 'change', valueChanged);
  listen(control, 'invalid', event => {
    event.preventDefault();
    query.focus();
    query.setAttribute('aria-invalid', 'true');
  });
  listen(focusRoot, 'focusin', event => {
    if (!focusRoot.contains(event.relatedTarget))
      control.dispatchEvent(new FocusEvent('focus', { relatedTarget: event.relatedTarget }));
  });
  listen(focusRoot, 'focusout', event => {
    if (!focusRoot.contains(event.relatedTarget))
      control.dispatchEvent(new FocusEvent('blur', { relatedTarget: event.relatedTarget }));
  });
  let resetTimer;
  listen(control.ownerDocument, 'reset', event => {
    if (event.target !== control.form) return;
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => {
      if (!event.defaultPrevented) {
        query.setCustomValidity?.('');
        attributes('reset');
      }
    }, 0);
  });
  const observer = new MutationObserver(records => {
    const optionsChanged = records.some(
      record =>
        record.type === 'childList' ||
        record.type === 'characterData' ||
        (record.target !== control && control.contains(record.target)),
    );
    attributes(control.value !== value ? 'value' : optionsChanged ? 'options' : 'attributes');
  });
  observer.observe(control, { attributes: true, childList: true, characterData: true, subtree: true });
  for (let ancestor = control.parentElement; ancestor; ancestor = ancestor.parentElement) {
    if (ancestor.tagName === 'FIELDSET')
      observer.observe(ancestor, { attributes: true, attributeFilter: ['disabled'] });
  }
  component.$nextTick(() => {
    if (control._x_model)
      cleanups.push(
        component.$watch(
          () => control._x_model.get(),
          () => component.$nextTick(valueChanged),
        ),
      );
    attributes('initial');
    if (
      (control.autofocus || wasFocused) &&
      [control.ownerDocument.body, control].includes(control.ownerDocument.activeElement)
    )
      query.focus();
  });
  attributes('initial');
  return () => {
    observer.disconnect();
    clearTimeout(resetTimer);
    cleanups.forEach(dispose => dispose?.());
  };
}

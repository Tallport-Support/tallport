/** Whether a file matches a native accept list such as "image/*,.pdf". */
const accepts = (input, file) => {
  const rules = (input.accept || '')
    .split(',')
    .map(rule => rule.trim().toLowerCase())
    .filter(Boolean);
  if (!rules.length) return true;
  const name = file.name.toLowerCase();
  const type = (file.type || '').toLowerCase();
  return rules.some(rule =>
    rule.startsWith('.')
      ? name.endsWith(rule)
      : rule.endsWith('/*')
        ? type.startsWith(rule.slice(0, -1))
        : type === rule,
  );
};

/**
 * Lets files be dropped onto the label of a native file input. The input keeps its name, accept,
 * multiple and models; a drop assigns its files and sends input and change, as choosing them would.
 */
export function fruitDropzone() {
  let controller;
  return {
    init() {
      const zone = this.$el;
      const input = zone.querySelector('input[type="file"]');
      if (!input) return;
      controller = new AbortController();
      const listen = (event, handler) => zone.addEventListener(event, handler, { signal: controller.signal });
      let depth = 0;
      const hasFiles = event => [...(event.dataTransfer?.types ?? [])].includes('Files');
      listen('dragenter', event => {
        if (!hasFiles(event) || input.disabled) return;
        event.preventDefault();
        depth += 1;
        zone.setAttribute('data-dragging', '');
      });
      listen('dragover', event => {
        if (!hasFiles(event) || input.disabled) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
      });
      listen('dragleave', () => {
        depth = Math.max(0, depth - 1);
        if (!depth) zone.removeAttribute('data-dragging');
      });
      listen('drop', event => {
        if (!hasFiles(event) || input.disabled) return;
        event.preventDefault();
        depth = 0;
        zone.removeAttribute('data-dragging');
        const files = [...event.dataTransfer.files].filter(file => accepts(input, file));
        if (!files.length) return;
        const transfer = new DataTransfer();
        for (const file of input.multiple ? files : files.slice(0, 1)) transfer.items.add(file);
        input.files = transfer.files;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    },
    destroy() {
      controller?.abort();
    },
  };
}

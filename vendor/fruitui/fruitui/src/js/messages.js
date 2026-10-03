/** Override generated text per widget without changing value or keyboard semantics. */
export function fruitMessage(root, key, fallback, values = {}) {
  const template = root.getAttribute(`data-fruit-${key}`) ?? fallback;
  return template.replace(/\{(\w+)\}/g, (match, name) => String(values[name] ?? match));
}

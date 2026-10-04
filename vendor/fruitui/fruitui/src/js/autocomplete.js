import { publishValue } from './control-bridge.js';
import { suggestionList } from './suggestions.js';

/**
 * Suggest completions for the word being typed in a native input or textarea. Options come from
 * a <datalist> inside the root, which the application (or a Livewire re-render) keeps current.
 * With data-fruit-trigger (for example "@"), only words that start with the trigger complete.
 */
export function fruitAutocomplete() {
  let root, control, suggestions;
  const trigger = () => root.dataset.fruitTrigger || '';

  /** The word before the caret: its start index and query text, or null. */
  const currentToken = () => {
    const end = control.selectionStart ?? control.value.length;
    const before = control.value.slice(0, end);
    const start = before.search(/[^\s,]*$/);
    const word = before.slice(start);
    const mark = trigger();
    if (mark) return word.startsWith(mark) ? { start, end, query: word.slice(mark.length) } : null;
    return word ? { start, end, query: word } : null;
  };
  return {
    init() {
      root = this.$el;
      control = root.querySelector('input:not([type="hidden"]), textarea');
      if (!control) return;
      let token = null;
      suggestions = suggestionList(root, control, {
        query() {
          token = currentToken();
          return token && token.query;
        },
        pick(option) {
          // Publishing dispatches input, which recomputes the token; keep this insertion's range.
          const { start, end } = token;
          const value = control.value;
          const insert = `${option.value}${trigger() ? ' ' : ''}`;
          publishValue(control, value.slice(0, start) + insert + value.slice(end));
          const caret = start + insert.length;
          control.setSelectionRange?.(caret, caret);
          control.focus();
          suggestions.hide();
        },
      });
    },
    destroy() {
      suggestions?.destroy();
    },
  };
}

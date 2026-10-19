import Alpine from 'alpinejs';
import fruitUI from './alpine.js';

// Code outside Alpine asks and announces through these (window.FruitUI in standalone.global.js).
export { confirm, dialog, toast } from './alpine.js';

/**
 * The prebuilt standalone.global.js, for a page with neither Livewire nor a bundler: Alpine itself,
 * with FruitUI registered, started as Alpine's own browser build starts (in a microtask), so load
 * plugins such as editor.global.js before it and register the page's own Alpine data on alpine:init.
 * A page that already has Alpine keeps it: FruitUI registers there and no second instance starts.
 * With Livewire, load livewire.global.js instead.
 */
if (window.Alpine) fruitUI(window.Alpine);
else {
  window.Alpine = Alpine;
  fruitUI(Alpine);
  queueMicrotask(() => Alpine.start());
}

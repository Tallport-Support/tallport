import fruitEditor from './editor.js';

/**
 * The prebuilt editor.global.js: registers the rich editor on the page's Alpine (including the one
 * Livewire injects), before or after livewire.global.js. ES module users call fruitEditor themselves.
 */
const register = () => window.Alpine && fruitEditor(window.Alpine);
if (window.Alpine) register();
else document.addEventListener('alpine:init', register, { once: true });

export default fruitEditor;

import fruitUI from './alpine.js';

// Code outside Alpine asks and announces through these (window.FruitUI in livewire.global.js).
export { confirm, dialog, toast } from './alpine.js';

/**
 * Registers FruitUI on the Alpine instance that Livewire injects. Import it from the app's Vite
 * entry (or load the compiled livewire.global.js): Livewire exposes window.Alpine and starts it on
 * DOMContentLoaded, after module scripts have run.
 */
if (window.Alpine) fruitUI(window.Alpine);
else document.addEventListener('alpine:init', () => fruitUI(window.Alpine), { once: true });

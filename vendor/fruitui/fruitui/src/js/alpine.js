import { fruitToast, toast } from './toast.js';
export { fruitToast, toast } from './toast.js';
import { confirm, fruitConfirmer } from './confirm.js';
import { fruitCopy } from './copy.js';
import { fruitListSelection } from './list-selection.js';
import { dialog, listenForRemoteDialogs } from './remote-dialog.js';
export { dialog } from './remote-dialog.js';
export { confirm, fruitConfirmer } from './confirm.js';
import { fruitDialogModel, listenForNamedDialogs } from './dialog.js';
import { fruitSplitter } from './splitter.js';
import { fruitHistory } from './history.js';
import { listenForBusyButtons } from './busy.js';
import { fruitAutocomplete } from './autocomplete.js';
import { fruitCommandPalette } from './command-palette.js';
import { fruitDropzone } from './dropzone.js';
import { fruitDatePicker, fruitColorPicker } from './pickers.js';
import { fruitEditorFallback, EDITOR_PLUGIN } from './editor-content.js';
import { fruitCombobox, fruitTokenField, fruitSelectionBar } from './selection.js';
import { fruitMenu, fruitContextMenu, fruitTooltip, fruitTabs, fruitFloatingDisclosure } from './navigation.js';

let listening = false;

/** Register on your existing Alpine instance before it starts (including Livewire's instance). */
export default function fruitUI(Alpine) {
  // Without the separate rich editor plugin, the native textarea still answers content requests.
  if (!Alpine[EDITOR_PLUGIN]) Alpine.data('fruitEditor', fruitEditorFallback);
  Alpine.data('fruitToast', fruitToast);
  Alpine.magic('toast', () => toast);
  Alpine.data('fruitConfirmer', fruitConfirmer);
  Alpine.magic('confirm', () => confirm);
  Alpine.data('fruitCopy', fruitCopy);
  Alpine.data('fruitListSelection', fruitListSelection);
  Alpine.magic('dialog', () => dialog);
  Alpine.data('fruitCombobox', fruitCombobox);
  Alpine.data('fruitTokenField', fruitTokenField);
  Alpine.data('fruitSelectionBar', fruitSelectionBar);
  Alpine.data('fruitAutocomplete', fruitAutocomplete);
  Alpine.data('fruitCommandPalette', fruitCommandPalette);
  Alpine.data('fruitDropzone', fruitDropzone);
  Alpine.data('fruitMenu', fruitMenu);
  Alpine.data('fruitDatePicker', fruitDatePicker);
  Alpine.data('fruitColorPicker', fruitColorPicker);
  Alpine.data('fruitContextMenu', fruitContextMenu);
  Alpine.data('fruitTooltip', fruitTooltip);
  Alpine.data('fruitTabs', fruitTabs);
  Alpine.data('fruitSplitter', fruitSplitter);
  Alpine.data('fruitHistory', fruitHistory);
  Alpine.data('fruitFloatingDisclosure', fruitFloatingDisclosure);
  Alpine.data('fruitDialogModel', fruitDialogModel);
  if (typeof window !== 'undefined' && !listening) {
    listenForNamedDialogs(window);
    listenForRemoteDialogs(document);
    listenForBusyButtons(document);
    listening = true;
  }
}

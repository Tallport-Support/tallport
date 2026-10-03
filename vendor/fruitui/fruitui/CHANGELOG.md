# Changelog

## Unreleased (0.1.0 development)

Breaking changes in this pass, with migration:

- **Conversation List/Row is now Item List/Row.** Rename `x-fruit::conversation-list`/`conversation-row` to `x-fruit::item-list`/`item-row`, `f-conversation-*` classes to `f-item-*`, and `--f-conversation-*` tokens to `--f-item-*`. Markup and behavior are unchanged.
- **Mail CSS is opt-in.** `fruitui/css` (and `build/fruitui.css`) no longer include the Mail reference layout. Add `fruitui/mail.css` (or `mail.compat.css`) if you use `f-mail`.
- **Text uses rem.** Font sizes are `--f-text-*` tokens that follow the reader's browser text size, identical at the default size. An html `.fruit-ui` scope now sizes its body instead of html. Hosts that set a pixel font size on html (Bootstrap 3) add `--f-text-root: 16px` to their scope. The reference examples use the same scale; only their 16px phone text-field sizes stay in pixels to prevent iOS input zoom.
- **A web reading scale.** Text is larger: body 16px (was 14px), controls 15px (was 13px), nothing below 12px; headings grow accordingly. Buttons and fields are 36px tall (small 30px, large 44px). Off-scale example sizes now use the scale. Layouts sized around the old text may need wider columns.
- **Field shows shared validation errors.** Without an `error` prop, Field shows the `$errors` message for its control's `wire:model` key or `name`. Pass `error=""` to opt out for a field.
- `x-fruit::attachment download` now renders `download=""` and keeps the URL's filename (it previously named every file "download").
- **The package no longer registers `<livewire:fruit-mail-preferences />`.** The example moved to `examples/laravel/mail-preferences.blade.php`; register it yourself with `Livewire::addComponent()` if you used it.
- **Field `control-id` is optional and Field associates exactly one control.** Without it, the id comes from the child's id or its `wire:model`/`name` (`field-form-email`). A Field containing two controls now throws; use Fieldset for groups.
- **Inputs no longer change their border color on focus;** the shared focus ring (`--f-focus-ring-*`) is the focus indicator everywhere.
- **One toast path.** `x-fruit::toast` is removed; use `<x-fruit::toaster />` (or one `x-data="fruitToast"` outlet in HTML) and send messages with `Fruit::toast()`, the `$toast()` Alpine magic or `toast()` from `fruitui/alpine`. Spreading `...fruitToast()` into application state and `destroyToast()` are gone; `fruitToast` attaches its own pause/resume and `fruit-toast` listeners and uses the top layer.
- **`fruitDialog` is removed.** Use native `showModal()`/`close()` with `<form method="dialog">`, a `name` with events, or `wire:model`.
- **Menu placement** is `placement="above"` in Blade (`f-menu--above` in HTML), matching Floating Disclosure; `data-placement` is no longer read.
- Dialogs rendered by `x-fruit::dialog` carry `wire:ignore.self`, so Livewire morphs no longer close an open dialog. Server-changed dialog attributes need a `wire:key` change to re-render.

Added:

- Blade adapters for Avatar, Badge, Tooltip, Sidebar (`sidebar`, `sidebar-group`, `sidebar-item`) and a managed `toaster` outlet.
- Named dialogs (`name`) opened and closed by `fruit-dialog-open`/`fruit-dialog-close` events; `fruitToast({ message })` initial messages.
- `FruitUI\Fruit::toast()`, `flashToast()`, `openDialog()` and `closeDialog()`, callable from components, form objects, actions and controllers.
- `Fruit::tokens()` splits a Token Field value into a clean array, and the `FruitUI\Rules\Tokens` rule validates every token with ordinary Laravel rules, reporting under the field's own key.
- A password `x-fruit::input` may bind `x-bind:type` for a show/hide toggle. Every other client type binding is still rejected.
- Translations of every component string into 27 languages (az, ca, cs, de, es, fi, fr, he, hr, hu, it, ja, kz, nl, no, pl, pt-BR, pt-PT, ro, ru, sk, sl, sv, tr, uk, zh-CN, zh-TW), loaded automatically; publish them with the `fruit-lang` tag. The editor's default toolbar labels are now static translation keys.
- **Date and color pickers.** `x-fruit::date` (date, datetime-local) and `x-fruit::color` now render a wrapper (`f-date-picker`, `f-color-picker`) around the native input and open FruitUI's calendar or swatch palette (with a custom saturation, hue and hex editor) instead of the browser's unstyled popups. The input keeps its value, attributes and bindings; style the input as before and pass wrapper presentation through `wrapper`. Time fields hide Chromium's clock button. Raw HTML keeps working with the browser pickers until it adds the wrapper.
- Date, date-time, month, week, time and color fields use the shared rounded field frame in Safari and other WebKit browsers, which kept a native square frame; date and time fields also match the text field height.
- Pull-down chevrons (`f-menu__chevron`): a Menu with a text title shows one automatically; custom triggers add it, alone in an `f-button--icon` trigger for split buttons.
- Headings in the scope use the text color and the title scale (h1 Title 1, h2 Title 2, h3 Title 3, h4–h6 Headline), and `f-large-title`, `f-title-1`…`f-title-3`, `f-headline`, `f-subheadline`, `f-footnote` and `f-caption` provide Apple's text styles. Headings without their own styles change size.
- The compat build now honours `data-theme="dark"` and `data-theme="class"`; their selectors kept zero specificity there and lost to the light default.
- Choices placed directly in a Fieldset stack one per row, as macOS lists checkbox and radio groups. Checkbox, Radio and Switch take a `description` (string or named slot) shown under the label and linked with `aria-describedby`.
- Compiled assets ship in the Composer package (`build/`), and `php artisan vendor:publish --tag=fruit-assets` copies them to `public/vendor/fruitui` for hosts without a bundler; they are also in the `laravel-assets` group that Laravel re-publishes after updates. The package archive leaves out the showcase pages and tooling.
- `--f-tint`: one brand color from which every accent shade derives in both appearances; the default reproduces the previous blues. Corner radii use a token scale (`--f-radius-xs` to `--f-radius-full`); a few radii moved by 1–2px to the nearest step, and combobox and autocomplete popups now match the menu's radius.
- Message `actions` slot (`f-message__actions`): quick actions shown on hover or focus, used by Chat (react, reply in thread) and Support (quote in reply). Chat adds Jump to latest; split buttons' menu triggers match their button's height.
- `x-fruit::context-menu` (`f-context-menu`): commands for an item on right-click, Shift+F10 or the context-menu key, using the existing menu items. Command and menu shortcuts now read as text rather than keycaps.
- `Livewire::test()` assertions: `assertToasted()`, `assertNotToasted()`, `assertDialogOpened()` and `assertDialogClosed()`.
- The Laravel Support desk example loads the customer's earlier conversations in a lazy Livewire island with a Skeleton placeholder, validates Cc with the `Tokens` rule, and documents islands and lazy placeholders.
- Dialog open state binds with `wire:model`/`x-model`; Escape and `<form method="dialog">` update it.
- `fruit::pagination.default` for Laravel paginators, and a `fruit` Livewire pagination theme (`'pagination_theme' => 'fruit'`) for length-aware and simple paginators.
- `fruitui/livewire` (`src/js/livewire.js`, `build/livewire.global.js`) registers the helpers on Livewire's injected Alpine; CSS and JS import from `vendor/fruitui/fruitui/src` without npm. Tailwind v4 layer order is documented and tested.
- Livewire 4 request states: forms keep their appearance while `wire:submit` locks them (previously every control dimmed or grayed), busy `[data-loading]` buttons show a progress cursor, and `wire:navigate` links marked `data-current` are highlighted.
- Field reads named error bags (`bag`) and accepts Checkbox, Radio and Switch.
- `--f-focus-ring-*` tokens and one focus ring across controls, panes, tables and editors; pressed states; Increase Contrast (`prefers-contrast: more`) support; search icon and combobox chevron stay centered at any control height.
- `php artisan vendor:publish --tag=fruit-views` publishes the views.
- Apple platform conventions: selection is emphasized (accent fill) while its sidebar, list or table has focus and unemphasized (`--f-selection-inactive`) otherwise; menu and combobox highlights use the accent fill; menus and combobox lists use a translucent `--f-material-popup` (opaque with reduced transparency); popovers, dialogs and toasts use `--f-surface-elevated`; the switch's off state is light with an accessible boundary; buttons, icon buttons and text fields share one control height and Button gains `size` (small, regular, large); tooltips wait 0.6s on hover; dialog backdrops dim without blurring; spinners slow instead of stopping under reduced motion; selected items keep their font weight.
- New components: Badge `tone` (accent, success, warning, danger), `variant="outline"` and a `dot` slot; Menu Checkbox, Menu Radio, Menu Link, Menu Separator and Menu Group, plus `shortcut` hints on menu items; Blade adapters for Toolbar, Search, Spinner, Chip, Presence and Segmented/Segment; Skeleton placeholders; Divider (Chat days and Support's thread start use it); Timeline (Admin activity uses it); Breadcrumbs and Crumb (Admin uses them); a print stylesheet that leaves application chrome off paper.
- Message (`inline` and `stacked` layouts, `note` variant) extracted from the Support thread and Chat channel and thread messages; Divider gains `align="start"`. The Livewire Support desk renders its thread with `x-fruit::message`.
- Bulk selection: Checkboxes beside Item Rows get a checkbox column, and `x-fruit::selection-bar` (`f-selection-bar`) counts the selection and holds its actions. Admin's bulk bar and the Livewire Support desk use it.
- Text completions: `x-fruit::autocomplete` (`fruitAutocomplete`) suggests mentions, emoji, saved replies or words for a native input or textarea; the Livewire Support desk uses it for @mentions.
- Command palette: `x-fruit::command-palette` with Command, Command Link and Command Group (`fruitCommandPalette`), opened by a Cmd/Ctrl shortcut or by name. The Livewire Support desk adds one for mailboxes and conversation actions.
- File drop zone: `x-fruit::dropzone` (`fruitDropzone`) around a native file input; Mail compose and Support's new conversation use it.
- Blade adapters for upload rows (`upload-list`, `upload-row`) and notifications (`notification-group`, `notification`); a typing indicator (`x-fruit::typing`, used in Chat direct messages); a back control (`f-back`, `x-fruit::back-link`) shared by Mail, Support and Admin.
- `data-theme="class"` follows a Tailwind-style `.dark` class (Flux and Laravel's starter kits) instead of the system appearance.
- Livewire pagination scrolls the list's pane (or the component) back to the start after a page change.
- Guidance and a tested example for persistent app shells (`@persist` with `wire:navigate` and `data-current`), icon sets and infinite scroll (`wire:intersect`).
- Color tokens are declared once with `light-dark()` instead of three palette copies; appearance still follows the system or `data-theme`.
- Sidebar `header` and `footer` slots (`f-sidebar__header`, `f-sidebar__footer`) replace the account and workspace lockups each example reimplemented. `--f-icon-size` sizes icons; group children indent without `f-sidebar__item--nested`.
- Shared internals: one Blade `ComponentContract::control()` call per control adapter, one details-popup helper for Menu and Floating Disclosure, one marker reset, shared PHP and browser test helpers, and one component guide (`support-components.md` merged into `components.md`).
- The Support interface as a Livewire single-file component (`examples/laravel`) with browser tests for morphs, `wire:navigate`, validation, dialogs, toasts and pagination.
- Blade contracts live in `docs/component-catalog.json`; `npm run build:docs` generates the policy table. Gallery specimens render from `gallery/specimens` (`composer gallery`); tests fail when either is stale.
- Pint, Prettier and ESLint (`composer lint`, `npm run lint`, `composer format`, `npm run format`), checked in CI.

- Simplify the README to installation and basic usage; keep detailed guidance in the component and adoption docs.
- Require Laravel 13 and PHP 8.3+, with Livewire 4 for optional server interactions. Remove older framework CI jobs and the Laravel 11 advisory exception.
- Convert Mail preferences to a native Livewire 4 single-file component using the class-based format previously provided by Volt.

- Add standalone layered core, Layout, Mail and Editor CSS and generated compatibility styles for existing unlayered hosts; preserve the all-in-one CSS entry.
- Ship ES module and browser-global helper bundles, complete package documentation, and optional editor peers. Core installation no longer pulls in Tiptap.
- Fix nested Tabs/Menu ownership, dynamic Combobox options, disabled ancestor fieldsets, enhancement presentation, and native focus/blur and editing event parity.
- Add Field associations, an Editor toolbar slot, translated default labels, generated-text hooks, inherited presentation tokens and logical CSS direction.
- Share Toast timing, replacement, pause/resume and cleanup across all reference applications.
- Add installed-package smoke checks, a real Laravel/Livewire browser host, PHP 8.3/8.5 checks, and Chromium/Firefox/WebKit CI jobs.
- Place Admin notifications in the toolbar and separate settings navigation from the form.

This is an unpublished development package. See docs/adoption.md for the upgrade and validation policy.

# Adopting FruitUI

FruitUI is an unpublished 0.1 development package, released under the [MIT license](../LICENSE). Public contracts are the checked catalog, component policy and this guide. The npm package stays marked private until its first release.

## Delivery and CSS coexistence

`npm run build` builds the showcase in `dist/` and distributable assets in `build/`. `npm pack` builds and ships source, compiled assets, and documentation. The default `fruitui/css` entry contains Core, Layout and Editor presentation. The Mail reference layout is opt-in: add `@import 'fruitui/mail.css';` after it if you use `f-mail`. For a smaller application stylesheet:

```css
@import 'fruitui/core.css';
/* Only if used: */
@import 'fruitui/layout.css';
@import 'fruitui/mail.css';
@import 'fruitui/editor.css';
```

Core includes controls, utilities and shared content patterns. Layout adds Toolbar, Sidebar, Workspace, Pane and Splitter presentation. Mail extends Core/Layout with only the reference Mail layout; Editor contains its optional presentation. Importing the editor's CSS does not install or initialize a rich editor.

Layered CSS is the default: application overrides can stay unlayered. Existing Bootstrap styles are also unlayered and therefore outrank layered declarations, even when loaded first. A legacy host can either put its existing styles into a lower cascade layer or use the generated **compatibility** styles after its existing stylesheet:

```html
<link rel="stylesheet" href="/css/bootstrap.css">
<link rel="stylesheet" href="/css/fruitui/core.compat.css">
<!-- Optional: layout.compat.css, then mail.compat.css, or editor.compat.css -->
<div class="fruit-ui">…FruitUI controls…</div>
```

Copy assets from `build/` to the host's public assets directory, or resolve `fruitui/dist/core.compat.css` in the host bundler. The all-in-one file is `fruitui.compat.css`. Both variants are generated from the same source; compatibility CSS removes layers and gives the scoped baseline normal class specificity. All generic resets stay within `.fruit-ui`, and component selectors use `f-*`. Do not put Bootstrap's `btn`/`form-control` and FruitUI classes on the same control. Hosts with more specific custom rules still need their own cascade review.

This fits FreeScout's existing Blade/Bootstrap/jQuery setup without changing its build pipeline. Start with a scoped screen; keep existing Select2 and Summernote controls until that screen deliberately adopts a FruitUI enhancement. FruitUI does not automatically replace controls or initialize another Alpine instance.

**Choosing a build.** Use the layered build (`fruitui.css`, or `core.css` plus `layout.css`) when your own styles live in cascade layers or are limited to your own classes: layered FruitUI always gives way to unlayered styles. Use the compat build (`*.compat.css`) while unlayered base rules from another framework, such as Bootstrap's bare `label`, `legend` or `code` rules, are still on the page: FruitUI's scoped rules then beat bare element rules by class specificity, and your own classes loaded after FruitUI still win. A host that has removed such a framework can move to the layered build.

**Base styles.** No host reset is needed. The `.fruit-ui` scope (an `html`, `body` or inner element) sets its margin, font, line height, text and background colors; it and everything inside it use `box-sizing: border-box`; buttons, inputs, selects and textareas inherit the font and color; headings, legends, code and keyboard keys follow the text color; and plain links use the accent color with a soft underline that firms up on hover. Components that may render as links (Item Row, Menu Item and Menu Link, Sidebar Item, Section Nav, Button and Back Link, Breadcrumbs, Command Link, Tab, Attachment, Pagination and Notification links) remove the underline and set their own color.

## JavaScript and optional editing

With Livewire's injected scripts (the default, as in Laravel's starter kits), import the self-registering entry from the app's Vite entry. It registers FruitUI on the Alpine instance Livewire injects, before Livewire starts it:

```js
import '../../vendor/fruitui/fruitui/src/js/livewire.js'; // or 'fruitui/livewire' from npm
```

With Livewire's [manual bundling](https://livewire.laravel.com/docs/4.x/alpine#manually-bundling-alpine-in-your-javascript-build), import `{ Livewire, Alpine }` from `vendor/livewire/livewire/dist/livewire.esm`, call `fruitUI(Alpine)`, then `Livewire.start()`. Without Livewire, register on your own instance:

```js
import Alpine from 'alpinejs';
import fruitUI from 'fruitui/alpine';
fruitUI(Alpine);
Alpine.start();
```

Without a bundler, `build/livewire.global.js` self-registers like the Livewire entry and exposes `FruitUI.confirm`, `FruitUI.dialog` and `FruitUI.toast` for code outside Alpine (`FruitUI.confirm({ title, confirm: 'Delete', tone: 'danger' }).then(…)`), and `alpine.global.js` exposes `FruitUI.default(Alpine)`, `FruitUI.toast`, `FruitUI.confirm` and `FruitUI.dialog`; load either before Alpine starts. The helpers never include or start Alpine.

Core installs no editor packages. To import the source `fruitui/editor` module, install its optional peers (`@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, compatible 3.x) in the host. Alternatively, `fruitui/dist/editor.js` bundles those dependencies; `editor.global.js` exposes `FruitEditor(Alpine)`. Register fruitUI first, then fruitEditor on that same instance (in `alpine:init` with injected Livewire scripts), and load Editor CSS. Core registers a quiet native Editor fallback; the optional plugin replaces it before Alpine starts. Without it, Editor remains a native textarea; Token Field and Combobox also preserve editable native fallbacks without Alpine.

All form names, IDs, validation and models belong to the canonical select/textarea. Only generated presentation containers use `wire:ignore`; never ignore the whole control wrapper. Editor sends `input` during editing and one `change` when focus leaves the widget, including its toolbar; it forwards native `focus`/`blur`. Discrete option/token commits send `input` and `change`. An unchanged value sends no duplicate events. Resets and external value updates do not emit user edit events.

Livewire 4 uses `.live.change`/`.live.blur` to send network updates on change/blur. FruitUI forwards events without changing those semantics. Server HTML sanitization, recipient validation, permission checks and upload transport stay with the application.

## Laravel and single-file Livewire components

The Composer service provider registers the `x-fruit::` Blade adapters. Use them inside a `.fruit-ui` scope; plain Blade usage does not require Livewire. Blade controls retain their native attributes, including `name`, `required`, `wire:model`, and action bindings. `php artisan vendor:publish --tag=fruit-views` copies the views to `resources/views/vendor/fruit` for local changes; published views take precedence.

With Tailwind v4, declare `@layer theme, base, fruit, components, utilities;` before importing Tailwind and FruitUI, so utility classes still override FruitUI's layered component styles.

Install optional server behavior with `composer require "livewire/livewire:^4.0"`. The reference examples in `examples/laravel` are Livewire 4 single-file components (the class-based format previously supplied by Volt; [Livewire's migration guide](https://livewire.laravel.com/docs/4.x/upgrading#upgrading-volt) explains it). The package does not register them; the browser host does, and applications can copy them. Clear compiled views with `php artisan view:clear` when updating.

## Fields and presentation ownership

Field associates one control with its label, description and validation error; see [Field associations](components.md#field-associations-and-validation-errors). Supported child adapters are Input, Textarea, Select, Number, Date, Time, File, Color, Range, Checkbox, Radio, Switch, Combobox, Token Field and Editor. They inherit a namespaced Field association, isolated from unrelated parent component props; a mismatched child ID is rejected.

Enhanced adapters accept `:wrapper="['class' => 'account-picker', 'style' => 'max-width:20rem', 'data-fruit-no-matches' => __('No matches')]"`. The wrapper accepts id/class/style/dir/lang/data attributes. Native attributes and bindings remain on the control. Native class/style are mirrored to visible presentation, retaining the same no-JavaScript fallback. Token Field maxlength limits the complete newline-serialized string.

Editor accepts a named `toolbar` slot for translated/custom button content. Use native buttons with `data-fruit-command` set to bold, italic, bulletList, orderedList, blockquote, undo or redo. Unknown commands remain disabled. Default toolbar labels, menu/pagination labels, generated helper text and the `Tokens` rule message use Laravel's `__()` with the English text as the key; wrapper overrides take precedence.

FruitUI ships Azerbaijani (`az`), Catalan (`ca`), Chinese (`zh-CN`, `zh-TW`), Croatian (`hr`), Czech (`cs`), Dutch (`nl`), Finnish (`fi`), French (`fr`), German (`de`), Hebrew (`he`), Hungarian (`hu`), Italian (`it`), Japanese (`ja`), Kazakh (`kz`), Norwegian (`no`), Polish (`pl`), Portuguese (`pt-BR`, `pt-PT`), Romanian (`ro`), Russian (`ru`), Slovak (`sk`), Slovenian (`sl`), Spanish (`es`), Swedish (`sv`), Turkish (`tr`) and Ukrainian (`uk`) strings in `lang/{locale}.json`, plus `lang/en.json` listing every key. File names match the application locale exactly, so a locale named `pt-BR` reads `pt-BR.json`. Counts use phrasing that is grammatical for any number rather than plural forms, because Laravel cannot pick plural forms for hyphenated locale names. They follow the application locale with no setup. To change a string, add the same key to the application's `lang/{locale}.json`, which takes precedence, or publish FruitUI's files with `php artisan vendor:publish --tag=fruit-lang` and edit them in `lang/vendor/fruit`. Keep curly `{count}` placeholders (filled in the browser) and `:count`-style placeholders (filled by Laravel) as they are. A new locale only needs a `lang/vendor/fruit/{locale}.json` with the keys from `en.json`.

## Customization, direction and generated text

**Accent color.** Like the system accent color, people can pick one of FruitUI's named accents: blue (default), purple, pink, red, orange, yellow, green or graphite. Set `data-fruit-accent="purple"` on the `.fruit-ui` scope or an ancestor (usually `html`), from an installation default and a per-user preference. Links, filled buttons, selection, switches, focus rings and the bar on your own replies follow it in both appearances; each accent keeps 4.5:1 contrast. Status colors (success, warning, danger), note yellow and the generated indigo keep their meaning. `<x-fruit::accent-picker name="accent" wire:model="accent" />` offers the choice as a radio group of colored circles; validate with `Rule::in(FruitUI\Fruit::ACCENTS)`. To preview before saving, set the attribute when the choice changes: `x-on:change="document.documentElement.dataset.fruitAccent = $event.target.value"`.

**Brand color.** Set `--f-tint` on the `.fruit-ui` scope to your brand color; accents, button fills and hovers, selection and focus rings derive from it in both appearances. Set it where `.fruit-ui` is (the derived tokens are computed there); to brand a nested area, give that element the `fruit-ui` class too. Choose a tint dark enough for white text on filled buttons.

```css
.fruit-ui { --f-tint: #248a3d; }
```

**Corner radii** come from `--f-radius-xs` (4px), `--f-radius-sm` (6px, controls), `--f-radius-md` (8px, rows and panels), `--f-radius` (10px, popups and cards), `--f-radius-lg` (18px, dialogs) and `--f-radius-full` (pills and tracks). Nested shapes subtract their container's padding, so adjusting a token keeps curves concentric.

Component customization tokens inherit from an application scope. `--f-internal-*` variables implement variant defaults and are private; application reference layouts may still assign their own geometry tokens locally. A component uses its documented fallback rather than resetting that token on itself. Color overrides must provide both appearances. The existing default dimensions are retained; scopes can adjust `--f-control-height`, `--f-control-font-size`, `--f-control-line-height`, `--f-control-padding-block`, `--f-control-padding-inline`, `--f-button-height`, `--f-button-padding-block`, `--f-button-padding-inline`, and `--f-field-gap`. Coarse-pointer controls retain a 44px target floor and 16px input text. Size tokens change presentation only. Text uses rem-based `--f-text-*` tokens and follows the reader's browser text size. Hosts that set a pixel font size on html, such as Bootstrap 3's 10px, add `--f-text-root: 16px` to their `.fruit-ui` scope.

Set `dir="rtl"` on the application scope for logical shared spacing, search icons, pane borders, switch thumbs and popup alignment. Tab and token directional keys follow inline direction; splitters already account for RTL. Reference applications retain their own routing, language and column arrangements; product-level translation and mirroring still belong to the host.

Generated English text can be replaced on the helper root (or the Blade wrapper bag):

| Helper | Attributes and placeholders |
| --- | --- |
| Combobox | `data-fruit-no-matches` |
| Token Field | `data-fruit-placeholder`, `data-fruit-remove-label` (`{value}`), `data-fruit-removed-message` (`{value}`), `data-fruit-count-message` (`{count}`), `data-fruit-invalid-message`, `data-fruit-length-message` (`{count}`) |
| Splitter | `data-fruit-value-text` (`{count}` pixels) |

Text is rendered as text, with no HTML interpolation. For pluralization, set the template appropriate to the host language or supply application announcements. Visible supplied labels/options, validation messages and Toast text remain host content.

## Shared notices

A page has one toast outlet: `<x-fruit::toaster />`, or in HTML `<div class="f-toast" role="status" x-data="fruitToast" x-show="notice" x-text="notice" x-cloak></div>`. Send messages with `Fruit::toast()`, `$toast('…')` in Alpine expressions and component methods, or `toast()` from `fruitui/alpine`. `fruitToast({ duration: 4000 })` replaces the current message and cancels its timer; duration zero keeps it until replaced. `$toast('Could not connect.', { tone: 'danger' })` shows an error: an icon, an assertive announcement and twice the duration. Ask before an action with one `<x-fruit::confirmer />` and `$confirm({ title, message, confirm, tone })`, which resolves true or false. For messages requiring a response, use a persistent Alert or a Dialog. See [server feedback](components.md#server-feedback-dialogs-toasts-and-confirmations).

## Compatibility and upgrades

Adapters require PHP 8.3+ and Laravel 13; optional Livewire integrations require Livewire 4. Laravel 11/12 and Livewire 3 are no longer supported. CI uses Testbench 11 with Laravel 13, checks PHP 8.3 and 8.5, and runs PHP contracts, packaged installation checks and actual browser/server interaction. The [workflow](../.github/workflows/test.yml) specifies the combinations; check its [latest results](https://github.com/nielspeen/fruitui/actions/workflows/test.yml) for current verification. The local environment is PHP 8.5, Laravel 13 and Livewire 4.

Browser CI targets Chromium, Firefox and WebKit. Locally choose `FRUITUI_BROWSERS=chromium,firefox,webkit npm test` after installing Playwright browsers. These engines do not verify every browser's or operating system's native pickers. Unsupported custom-select styling falls back to a native picker; lack of Popover support retains the CSS-positioned disclosure/list fallback.

Required checks: `composer test`, `composer lint`, `npm run lint`, `npm run build`, `npm test`, and `npm run test:package`. The package smoke test installs an actual tarball in an isolated host, checks shipped docs/exports/bundles, and proves core installation does not pull in Tiptap. Browser coverage includes native/CSS fallbacks, themes, responsive layouts, keyboard/ARIA checks, nested ownership, dynamic options, resets, teardown and an actual Laravel/Livewire host. That host also serves the Support reference interface as a Livewire single-file component (`examples/laravel/support-desk.blade.php`), covering server re-renders around enhanced controls, `wire:navigate`, validation errors, server-opened dialogs, flashed toasts and Livewire pagination. Keep security, business operations and application-specific workflows in application tests.

During 0.x, changing native markup, serialized values, documented options, tokens, selectors or events requires a changelog and migration guidance; breaking changes require a minor version increment before release. Patch versions preserve those public contracts. `__` descendants remain documented presentation parts where listed in the catalog/guide; private JavaScript implementation details are not an integration API. Default all-in-one imports and canonical native value formats remain compatible in this pass. When updating a Laravel installation, clear compiled Blade views (`php artisan view:clear`), particularly for the Field adapter. The corrected Editor change timing affects callers that previously relied on keystroke change events: use input/live bindings for immediate edits.

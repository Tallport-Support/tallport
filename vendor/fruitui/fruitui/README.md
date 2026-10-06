<p align="center"><img src="public/fruitui-logo.svg" width="96" height="96" alt=""></p>

# FruitUI

FruitUI is a CSS-first UI framework for HTML and Laravel. We try to follow Apple's Human Interface Guidelines. It includes native controls, reusable layouts, and optional Alpine.js and Livewire behavior. Light and dark appearances follow the system automatically through CSS.

Requires **Laravel 13 and PHP 8.3+** for the Blade adapters. Livewire is optional; integrations target **Livewire 4**. The package is in development and is not published to npm or Packagist yet.

## Use with Laravel

Until it is on Packagist, require it from GitHub:

```sh
composer config repositories.fruitui vcs https://github.com/FruitUI/fruitui
composer require fruitui/fruitui:dev-main
```

The Composer package contains the CSS and JavaScript sources, so Vite can import them from `vendor/` without an npm package. In `resources/css/app.css`:

```css
/* With Tailwind v4, declare this order first so utilities still override FruitUI. */
@layer theme, base, fruit, components, utilities;
@import 'tailwindcss';
@import '../../vendor/fruitui/fruitui/src/fruitui.css';
/* Only for the Mail reference layout: */
@import '../../vendor/fruitui/fruitui/src/mail.css';
```

In `resources/js/app.js`, register the interactive helpers on the Alpine instance that Livewire injects:

```js
import '../../vendor/fruitui/fruitui/src/js/livewire.js';
```

**Without a bundler** (Laravel Mix, plain asset hosting), publish the compiled files and link them. `*.compat.css` suits hosts whose own CSS is not in cascade layers; `livewire.global.js` registers on the Alpine instance Livewire injects:

```sh
php artisan vendor:publish --tag=fruit-assets
```

```blade
<link rel="stylesheet" href="{{ asset('vendor/fruitui/fruitui.compat.css') }}">
<script src="{{ asset('vendor/fruitui/livewire.global.js') }}" defer></script>
```

The files are also in Laravel's `laravel-assets` group, which the default Laravel skeleton re-publishes in Composer's `post-update-cmd` (`@php artisan vendor:publish --tag=laravel-assets --ansi --force`), so updates keep them current.

Wrap your layout in `fruit-ui` and use the Blade components:

```blade
<main class="fruit-ui">
    <x-fruit::card class="f-stack">
        <x-fruit::field label="Email">
            {{-- Shows the validation error for "email" from $errors automatically --}}
            <x-fruit::input type="email" name="email" required />
        </x-fruit::field>
        <x-fruit::button variant="primary">Continue</x-fruit::button>
    </x-fruit::card>
</main>
```

Use `data-theme="light"` or `data-theme="dark"` on the same container to override the system appearance, or `data-theme="class"` to follow a Tailwind-style `dark` class (as Flux and Laravel's starter kits toggle on `<html>`). Customize presentation with the shared `--f-` CSS tokens; `--f-tint` alone sets the brand color for every accent shade. To change a component's markup, `php artisan vendor:publish --tag=fruit-views` copies the views to `resources/views/vendor/fruit`. Component text follows the application locale, with 27 languages included; `--tag=fruit-lang` publishes the strings for editing.

## Use with HTML

Run `npm ci && npm run build:package` in this checkout, then copy `build/fruitui.css` into your application's public assets:

```html
<link rel="stylesheet" href="/css/fruitui.css">
<main class="fruit-ui">
    <button class="f-button f-button--primary" type="button">Continue</button>
</main>
```

The [component guide](docs/components.md) includes HTML and Blade examples. For Bootstrap coexistence, smaller CSS imports, and compiled JavaScript bundles, see [adoption](docs/adoption.md).

## Livewire and Alpine.js

Blade controls accept `wire:model`, `wire:click`, and other native bindings, and survive Livewire's re-renders. Livewire 4's request states are styled: a busy submit keeps the form's appearance with a progress cursor, and `wire:navigate` links marked current are highlighted.

Send feedback from components, form objects, actions or controllers. Put `<x-fruit::toaster />` and `<x-fruit::confirmer />` in your layout, then:

```php
use FruitUI\Fruit;

Fruit::toast('Conversation archived.');      // now; outside Livewire, on the next page
Fruit::toast('Could not connect.', tone: 'danger');
Fruit::flashToast('Closed.');                // on the next page, after a redirect
Fruit::openDialog('confirm-archive');        // <x-fruit::dialog name="confirm-archive">
```

A dialog can also bind its open state: `<x-fruit::dialog wire:model="confirming">`. To ask before an action, `$confirm({ title: 'Delete this mailbox?', confirm: 'Delete', tone: 'danger' }).then(confirmed => confirmed && $wire.delete())` resolves true or false. For pagination, set `'pagination_theme' => 'fruit'` in `config/livewire.php`; outside Livewire, use `$items->links('fruit::pagination.default')`.

Without Livewire, register the helpers on your own Alpine instance: `fruitUI(Alpine)` from `src/js/alpine.js`. See [JavaScript setup](docs/adoption.md#javascript-and-optional-editing).

## Explore the examples

Requires Node 20.19+ or 22.12+.

```sh
npm ci
npm run dev
```

Open [Mail](http://127.0.0.1:5173/), [Support](http://127.0.0.1:5173/support.html), [Chat](http://127.0.0.1:5173/chat.html), [Admin](http://127.0.0.1:5173/admin.html), [Settings](http://127.0.0.1:5173/settings.html), or the [component gallery](http://127.0.0.1:5173/components.html). These are interactive frontend examples using sample data.

The Support interface and a Mail preferences form also exist as Livewire 4 single-file components in [examples/laravel](examples/laravel/). With `npm run dev` running, start the bundled Laravel host with `composer install && node scripts/serve-host.mjs` and open [the Livewire Support desk](http://127.0.0.1:5180/support).

## Documentation

- [Components](docs/components.md): usage, slots, and customization.
- [Adoption](docs/adoption.md): integration, compatibility, and verification.
- [Component policy](docs/component-policy.md): contracts and contributor rules.

## License

FruitUI is open-source software licensed under the [MIT license](LICENSE). Its icons are from [Lucide](https://lucide.dev) (ISC license); see [third-party notices](THIRD_PARTY_NOTICES.md). Use Lucide for your application's own icons too, at the same stroke, so they match.

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">

        <title>@yield('title')</title>

        {{-- FruitUI's own stylesheet only: an error page shows even when the app can't. Light and dark follow the system. --}}
        <link rel="stylesheet" href="{{ asset('vendor/fruitui/core.compat.css') }}">
        <style>
            .error-page {
                display: grid;
                place-items: center;
                min-height: 100vh;
                padding: 16px;
                box-sizing: border-box;
            }
        </style>
    </head>
    <body class="fruit-ui">
        <main class="error-page">
            <div class="f-empty-state">
                <h1 class="f-empty-state__title">@yield('message')</h1>
                @hasSection('details')
                    <div class="f-empty-state__description">@yield('details')</div>
                @endif
                @hasSection('actions')
                    <div class="f-empty-state__actions">@yield('actions')</div>
                @endif
            </div>
        </main>
    </body>
</html>

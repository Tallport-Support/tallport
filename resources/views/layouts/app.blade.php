<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" @if (Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex,nofollow">
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {!! \Helper::cspMetaTag() !!}
    @php $app_name = \Eventy::filter('layout.title.name', config('app.name', 'Tallport')); @endphp
    <title>@if ($__env->yieldContent('title_full'))@yield('title_full') @elseif ($__env->yieldContent('title'))@yield('title') - {{ $app_name }} @else{{ $app_name }}@endif</title>

    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="shortcut icon" type="image/x-icon" href="@filter('layout.favicon', URL::asset('favicon.ico'))">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}" crossorigin="use-credentials">
    <link rel="mask-icon" href="{{ asset('safari-pinned-tab.svg') }}" color="#5bbad5">
    <meta name="msapplication-TileColor" content="#da532c">
    <meta name="theme-color" content="@filter('layout.theme_color', '#ffffff')">
    @action('layout.head')
    {{-- Styles --}}
    {{-- Conversation page must open immediately, so we are loading scripts present on conversation page --}}
    {{-- style.css must be the last to able to redefine styles --}}
    @php
        try {
            $styles= array('/css/fonts.css', '/vendor/fruitui/core.compat.css', '/vendor/fruitui/layout.compat.css', '/vendor/fruitui/editor.compat.css', '/css/style.css' );
            if (Helper::isLocaleRtl()) {
                $styles[] = '/css/style-rtl.css';
            }
    @endphp
    {!! Minify::stylesheet(\Eventy::filter('stylesheets', $styles)) !!}
    @php
        } catch (\Exception $e) {
            // Try...catch is needed to catch errors when activating a module and public symlink not created for module.
            \Helper::logException($e);
        }
    @endphp

    @yield('stylesheets')
    @livewireStyles
    @action('layout.after_stylesheets')
</head>
<body class="fruit-ui locale-{{ app()->getLocale() }} @if (Helper::isLocaleRtl()) rtl @endif @if (!Auth::user()) user-is-guest @endif @if (Auth::user() && Auth::user()->isAdmin()) user-is-admin @endif @yield('body_class') @action('body.class')" @yield('body_attrs') @if (Auth::user()) data-auth_user_id="{{ Auth::user()->id }}" @endif @if (Auth::user() && Auth::user()->hasKeyboardShortcuts()) data-keyboard-shortcuts="1" @endif>
<div id="app">

        @php
            $app_shell = Auth::user() && empty(app('request')->x_embed) && empty($__env->yieldContent('guest_mode'));
        @endphp
        @if ($app_shell)
            {{-- One FruitUI workspace, composed as in FruitUI's Support example: the
                 app sidebar, the list (list section), the page (toolbar and content
                 sections) and an inspector (inspector section). Each pane scrolls
                 by itself; a toolbar row runs across the top. --}}
            @php
                $has_list = trim($__env->yieldContent('list')) !== '';
                $has_list_toolbar = trim($__env->yieldContent('list_toolbar')) !== '';
                $has_toolbar = trim($__env->yieldContent('toolbar')) !== '';
                $has_inspector = trim($__env->yieldContent('inspector')) !== '';
            @endphp
            <x-fruit::workspace frame="fill" class="app-workspace {{ $__env->yieldContent('split_class') }}" :aria-label="\Config::get('app.name')" :data-list="$has_list ? '' : null" :data-inspector="$has_inspector ? '' : null">
                <header class="f-toolbar app-workspace__brand">
                    <button type="button" class="f-button f-button--ghost f-button--icon app-sidebar-toggle" aria-controls="app-sidebar" aria-expanded="false" x-data x-on:click="$el.setAttribute('aria-expanded', document.body.classList.toggle('app-sidebar-open'))" aria-label="{{ __('Toggle Navigation') }}"><x-icon.menu class="f-icon" aria-hidden="true" /></button>
                    @include('partials/app_sidebar_brand')
                </header>
                @include('partials/app_sidebar')

                @if ($has_list)
                    @if ($has_list_toolbar)
                        <header class="f-toolbar app-workspace__list-toolbar">@yield('list_toolbar')</header>
                    @endif
                    <section id="app-list" class="f-pane f-pane--column f-pane--border-end app-workspace__list @if (!$has_list_toolbar) app-workspace__pane--full @endif" aria-label="{{ __('Conversations') }}">
                        @yield('list')
                    </section>
                @endif

                @if ($has_toolbar)
                    <header class="f-toolbar app-workspace__toolbar">@yield('toolbar')</header>
                @endif
                <main id="app-content" class="f-pane f-pane--column app-workspace__content app-main @yield('main_class') @if (!$has_toolbar) app-workspace__pane--full @endif">
                    <div class="f-pane__scroll" id="app-content-scroll">
                        @if (($browser_check = \Helper::checkBrowser()) && $browser_check['msg'])
                            <x-fruit::alert tone="danger">{{ $browser_check["msg"] }}</x-fruit::alert>
                        @endif
                        @yield('sidebar')
                        @if ($__env->yieldContent('aside'))
                            <div class="layout-2col">
                                <div class="sidebar-2col">
                                    @yield('aside')
                                </div>
                                <div class="content-2col">
                                    @yield('content')
                                </div>
                            </div>
                        @else
                            <div class="content @yield('content_class')">
                                @yield('content')
                            </div>
                        @endif
                        @unless ($has_list)
                            @include('partials/app_footer')
                        @endunless
                    </div>
                </main>

                @if ($has_inspector)
                    <header class="f-toolbar app-workspace__inspector-toolbar">@yield('inspector_toolbar')</header>
                    <aside id="app-inspector" class="f-pane f-pane--scroll f-pane--border-start app-workspace__inspector" aria-label="@yield('inspector_label', __('Details'))">
                        @yield('inspector')
                    </aside>
                @endif

                <x-fruit::splitter pane="app-sidebar" flexible="app-content" variable="--f-sidebar-width" :min="200" :max="320" :reserve="360" :aria-label="__('Navigation')" class="app-workspace__splitter" style="--f-splitter-column: 1" />
                @if ($has_list)
                    <x-fruit::splitter pane="app-list" flexible="app-content" variable="--f-list-width" :min="280" :max="520" :reserve="360" :aria-label="__('Conversations')" class="app-workspace__splitter" style="--f-splitter-column: 2" />
                @endif
                @if ($has_inspector)
                    <x-fruit::splitter pane="app-inspector" flexible="app-content" variable="--f-inspector-width" :min="220" :max="380" :reserve="360" edge="start" :aria-label="__('Details')" class="app-workspace__splitter app-workspace__splitter--inspector" />
                @endif
            </x-fruit::workspace>
        @else
            @if (($browser_check = \Helper::checkBrowser()) && $browser_check['msg'])
                <x-fruit::alert tone="danger">{{ $browser_check["msg"] }}</x-fruit::alert>
            @endif
            <div class="content @yield('content_class')">
                @yield('content')
            </div>
        @include('partials/app_footer')
        @endif
    </div>

    <div id="loader-main"></div>

    @include('partials/floating_flash_messages')

    @yield('body_bottom')
    @action('layout.body_bottom')

    {{-- Scripts --}}
    @php
        try {
    @endphp
    {!! Minify::javascript(\Eventy::filter('javascripts', array('/js/lang.js', '/js/builds/vars.js', '/js/laroute.js', '/js/polycast/polycast.js', '/js/push/push.min.js', '/vendor/fruitui/livewire.global.js', '/vendor/fruitui/editor.global.js', '/js/tallport.js', '/js/mailboxes.js', '/js/users.js', '/js/customers.js', '/js/conversations.js', '/js/admin.js', '/js/editor.js', '/js/main.js', '/js/realtime.js', '/js/shortcuts.js', '/js/saved_replies.js', '/js/attachments.js', '/js/workflows.js', '/js/kb.js')), ['data-navigate-once' => true]) !!}
    @php
        } catch (\Exception $e) {
            // To prevent 500 errors on update.
            // Also catches errors when activating a module and public symlink not created for module.
            if (strstr($e->getMessage(), 'vars.js')) {
                \Artisan::call('tallport:generate-vars');
            }
            \Helper::logException($e);
        }
    @endphp
    @yield('javascripts')
    <script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
        @if (\Helper::isInApp())
            @if (Auth::user())
                fs_in_app_data['token'] = '{{ Auth::user()->getAuthToken() }}';
            @else
                fs_in_app_data['token'] = '';
            @endif
        @endif
        @yield('javascript')
        @action('javascript', $__env->yieldContent('javascripts'))
    </script>
    @if (Auth::user() && Auth::user()->hasKeyboardShortcuts())
        @include('partials/keyboard_shortcuts')
    @endif
    @livewireScripts(['nonce' => \Helper::cspNonce()])
</body>
</html>

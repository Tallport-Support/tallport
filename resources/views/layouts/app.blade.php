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
            $styles= array('/css/fonts.css', '/css/bootstrap.css', '/css/select2/select2.min.css', '/js/featherlight/featherlight.min.css', '/js/featherlight/featherlight.gallery.min.css', '/css/magic-check.css', '/vendor/fruitui/core.compat.css', '/vendor/fruitui/layout.compat.css', '/css/style.css' );
            if (Helper::isLocaleRtl()) {
                $styles[] = '/css/bootstrap-rtl.css';
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
<body class="locale-{{ app()->getLocale() }} @if (Helper::isLocaleRtl()) rtl @endif @if (!Auth::user()) user-is-guest @endif @if (Auth::user() && Auth::user()->isAdmin()) user-is-admin @endif @yield('body_class') @action('body.class')" @yield('body_attrs') @if (Auth::user()) data-auth_user_id="{{ Auth::user()->id }}" @endif @if (Auth::user() && Auth::user()->hasKeyboardShortcuts()) data-keyboard-shortcuts="1" @endif>
<div id="app">

        @php
            $app_shell = Auth::user() && empty(app('request')->x_embed) && empty($__env->yieldContent('guest_mode'));
        @endphp
        @if ($app_shell)
            {{-- The sidebar, then the page: its tabs (sidebar section), side column (aside section) and content. --}}
            <div class="app-shell">
                @include('partials/app_sidebar')
                <div class="app-main @yield('main_class')">
                    <div class="fruit-ui app-main__bar">
                        <button type="button" class="f-button f-button--ghost f-button--icon app-sidebar-toggle" aria-controls="app-sidebar" aria-expanded="false" aria-label="{{ __('Toggle Navigation') }}"><x-heroicon-o-bars-3 class="f-icon" aria-hidden="true" /></button>
                    </div>
                    @if (($browser_check = \Helper::checkBrowser()) && $browser_check['msg'])
                        <div class="alert alert-danger">{{ $browser_check['msg'] }}</div>
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
                @include('partials/app_footer')
                </div>
            </div>
        @else
            @if (($browser_check = \Helper::checkBrowser()) && $browser_check['msg'])
                <div class="alert alert-danger">{{ $browser_check['msg'] }}</div>
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
    {!! Minify::javascript(\Eventy::filter('javascripts', array('/js/jquery.js', '/js/bootstrap.js', '/js/lang.js', '/js/builds/vars.js', '/js/laroute.js', '/js/parsley/parsley.min.js', '/js/parsley/i18n/'.strtolower(Config::get('app.locale')).'.js', '/js/select2/select2.full.min.js', '/js/polycast/polycast.js', '/js/push/push.min.js', '/js/featherlight/featherlight.min.js', '/js/featherlight/featherlight.gallery.min.js', '/js/taphold.js', '/js/jquery.titlealert.js', '/vendor/fruitui/livewire.global.js', '/js/main.js', '/js/shortcuts.js', '/js/saved_replies.js', '/js/attachments.js', '/js/workflows.js', '/js/kb.js'))) !!}
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

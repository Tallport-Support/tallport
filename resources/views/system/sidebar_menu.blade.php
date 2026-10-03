<x-page-nav :label="__('System')">
    <x-slot:title><h1>{{ __('System') }}</h1></x-slot:title>
    <a href="{{ route('system') }}" @if (Route::is('system')) aria-current="page" @endif>{{ __('Status') }}</a>
    <a href="{{ route('system.tools') }}" @if (Route::is('system.tools')) aria-current="page" @endif>{{ __('Tools') }}</a>
</x-page-nav>

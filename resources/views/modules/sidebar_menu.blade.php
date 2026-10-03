<x-page-nav :label="__('Modules')">
    <x-slot:title><h1>{{ __('Modules') }}</h1></x-slot:title>
    <a href="#installed">{{ __('Installed Modules') }}@if (count($installed_modules)) <small>({{ count($installed_modules) }})</small>@endif</a>
</x-page-nav>

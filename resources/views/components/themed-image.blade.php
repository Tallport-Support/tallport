{{-- An image with a dark-mode version (Settings » Appearance): the dark one when the system is dark. --}}
@props(['src', 'dark' => ''])
<picture>@if ($dark !== '')<source srcset="{{ $dark }}" media="(prefers-color-scheme: dark)">@endif<img src="{{ $src }}" {{ $attributes }}></picture>

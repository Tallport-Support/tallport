@php(\FruitUI\Support\ComponentContract::validate('remote-dialog', $attributes))
{{-- One per layout: translated labels for dialogs opened by FruitUI.dialog(), $dialog() and
     [data-fruit-dialog-url] links. The template itself renders nothing. --}}
<template data-fruit-remote-dialog data-fruit-close-label="{{ __('Close') }}" data-fruit-loading-label="{{ __('Loading…') }}" data-fruit-error-message="{{ __('Could not load this content.') }}" data-fruit-retry-label="{{ __('Try Again') }}"></template>

{{-- A mailbox's settings page, in the mailbox (layouts/app): the list column holds its pages
     (mailboxes/settings_menu), and the page shows beside it under its title, with Back to the list in a
     narrow window. Its own pages include this through mailboxes/layout_page, modules' pages in their
     'sidebar' section. --}}
@php
    $menu_route = (string) Route::currentRouteName();
    $menu_page_title = in_array($menu_route, ['mailboxes.update', 'mailboxes.general']) ? __('General') : App\Misc\MailboxSettings::currentTitle($mailbox, Auth::user(), $menu_route);
    // Where the settings start, in a narrow window: the list first (MailboxesController::update()).
    $menu_list_first = $menu_route == 'mailboxes.update' && request()->cookie('tallport_narrow');
    $menu_page_url = App\Misc\MailboxSettings::pageUrl($mailbox);
@endphp
@section('list_toolbar')
    <div class="app-list-title">
        <h1>{{ __('Settings') }}</h1>
        <p>{{ $mailbox->name }}</p>
    </div>
@endsection
@section('list')
    @include('mailboxes/settings_menu')
@endsection
@if (!$menu_list_first)
    @section('split_class', 'split-view--open')
@endif
{{-- Settings start at the pane's edge (FruitUI), as the list beside them. --}}
@section('page_align', 'start')
@if ($menu_page_url)
    @section('body_attrs')@parent data-page-url="{{ $menu_page_url }}"@endsection
@endif
<x-page-nav class="mailbox-settings-nav" :label="__('Mailbox Settings')">
    <x-slot:back><x-fruit::back-link wire:navigate :href="route('mailboxes.update', ['id' => $mailbox->getSlug()])">{{ __('Settings') }}</x-fruit::back-link></x-slot:back>
    @if ($menu_page_title !== null)
        <x-slot:title><h1>{{ $menu_page_title }}</h1></x-slot:title>
    @endif
</x-page-nav>

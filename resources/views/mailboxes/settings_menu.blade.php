{{-- A mailbox's settings pages in the list column (mailboxes/sidebar_menu), the open one current: General,
     then its further pages with their current values (App\Misc\MailboxSettings), its channels (Email
     too) and its automatic messages each a group. Modules add theirs at the end with
     mailboxes.settings.menu (labels only). --}}
@php
    $settings_route = (string) Route::currentRouteName();
    $settings_pages = array_values(App\Misc\MailboxSettings::pages($mailbox, Auth::user()));
    // In their order, each run of a group's pages under its title; General first, modules' last.
    $settings_titles = ['channels' => __('Channels'), 'messages' => __('Automatic Messages')];
    $settings_groups = [[null, []]];
    foreach ($settings_pages as $settings_page) {
        $settings_title = $settings_titles[$settings_page['group']] ?? null;
        if (end($settings_groups)[0] !== $settings_title) {
            $settings_groups[] = [$settings_title, []];
        }
        $settings_groups[array_key_last($settings_groups)][1][] = $settings_page;
    }
    if (end($settings_groups)[0] !== null) {
        $settings_groups[] = [null, []];
    }
    $settings_module_pages = trim(preg_replace('#<a (?![^>]*\bclass=)#', '<a class="f-form-row f-form-row--link" ', App\Misc\MailboxSettings::modulePages($mailbox)));
    // A module's page marks its own link (its route may start like one of ours).
    $settings_current = fn ($page) => !str_contains($settings_module_pages, 'aria-current="page"')
        && collect($page['routes'])->contains(fn ($route) => $settings_route == $route || str_starts_with($settings_route, $route.'.'));
@endphp
<nav class="mailbox-settings" aria-label="{{ __('Mailbox Settings') }}">
    <x-fruit::page align="start" style="--f-page-background: var(--f-grouped-background)">
        @foreach ($settings_groups as $settings_index => [$settings_title, $settings_group])
            @php $settings_last = $settings_index == count($settings_groups) - 1; @endphp
            @if ($settings_index == 0 || $settings_group || ($settings_last && $settings_module_pages !== ''))
                <x-fruit::form-section :title="$settings_title" :level="2">
                    @if ($settings_index == 0 && App\Misc\MailboxSettings::canOpenMailbox($mailbox, Auth::user()))
                        <a wire:navigate class="f-form-row f-form-row--link" href="{{ route('mailboxes.general', ['id' => $mailbox->getSlug()]) }}" @if (in_array($settings_route, ['mailboxes.update', 'mailboxes.general'])) aria-current="page" @endif><span>{{ __('General') }}</span></a>
                    @endif
                    @foreach ($settings_group as $settings_page)
                        <a wire:navigate class="f-form-row f-form-row--link" href="{{ $settings_page['url'] }}" @if ($settings_current($settings_page)) aria-current="page" @endif><span>{{ $settings_page['label'] }}</span>@if ($settings_page['value'] !== null)<span class="f-form-row__value @if ($settings_page['problem']) mailbox-pages__problem @endif">{{ $settings_page['value'] }}</span>@endif</a>
                    @endforeach
                    @if ($settings_last)
                        {!! $settings_module_pages !!}
                    @endif
                </x-fruit::form-section>
            @endif
        @endforeach
    </x-fruit::page>
</nav>

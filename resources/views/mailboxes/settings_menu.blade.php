{{-- A mailbox's further settings pages, as rows on its page (mailboxes/update), each with its current
     value (App\Misc\MailboxSettings). Modules add theirs with mailboxes.settings.menu (labels only). --}}
@php
    $settings_pages = App\Misc\MailboxSettings::pages($mailbox, Auth::user());
    $settings_module_pages = trim(preg_replace('#<a (?![^>]*\bclass=)#', '<a class="f-form-row f-form-row--link" ', App\Misc\MailboxSettings::modulePages($mailbox)));
@endphp
@if ($settings_pages || $settings_module_pages !== '')
    <x-fruit::form-section class="mailbox-pages">
        @foreach ($settings_pages as $settings_page)
            <a wire:navigate class="f-form-row f-form-row--link" href="{{ $settings_page['url'] }}"><span>{{ $settings_page['label'] }}</span>@if ($settings_page['value'] !== null)<span class="f-form-row__value @if ($settings_page['problem']) mailbox-pages__problem @endif">{{ $settings_page['value'] }}</span>@endif</a>
        @endforeach
        {!! $settings_module_pages !!}
    </x-fruit::form-section>
@endif

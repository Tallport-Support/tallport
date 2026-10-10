{{-- A mailbox's further settings pages, as rows on its page (mailboxes/update), each with its current
     value (App\Misc\MailboxSettings). Modules add theirs with mailboxes.settings.menu (labels only). --}}
@php
    $settings_pages = App\Misc\MailboxSettings::pages($mailbox, Auth::user());
    $chat_pages = array_filter($settings_pages, fn ($page) => $page['chat']);
    $settings_pages = array_filter($settings_pages, fn ($page) => !$page['chat']);
    $settings_module_pages = trim(preg_replace('#<a (?![^>]*\bclass=)#', '<a class="f-form-row f-form-row--link" ', App\Misc\MailboxSettings::modulePages($mailbox)));
@endphp
@if (Auth::user()->can('updateSettings', $mailbox) || $chat_pages)
    <x-fruit::form-section :title="__('Chat')" id="chat" class="mailbox-pages">
        @can('updateSettings', $mailbox)
            <x-fruit::checkbox id="chat_start_new" name="chat_start_new" value="1" :checked="(bool) old('chat_start_new', $mailbox->getMeta('chat_start_new'))">{{ __('Start a new conversation when receiving a reply to the closed / deleted Chat conversation') }}</x-fruit::checkbox>
            <x-fruit::field :label="__('Reopen Window')" :description="__('A new message continues the latest chat conversation if it had activity within this many days; otherwise a new conversation is started.')" control-id="chat_reopen_days">
                <x-fruit::number id="chat_reopen_days" name="chat_reopen_days" :value="old('chat_reopen_days', App\Misc\ChatConversations::reopenDays($mailbox))" min="1" max="3650" required />
            </x-fruit::field>
        @endcan
        @foreach ($chat_pages as $settings_page)
            <a wire:navigate class="f-form-row f-form-row--link" href="{{ $settings_page['url'] }}"><span>{{ $settings_page['label'] }}</span><span class="f-form-row__value">{{ $settings_page['value'] }}</span></a>
        @endforeach
    </x-fruit::form-section>
@endif
@if ($settings_pages || $settings_module_pages !== '')
    <x-fruit::form-section class="mailbox-pages">
        @foreach ($settings_pages as $settings_page)
            <a wire:navigate class="f-form-row f-form-row--link" href="{{ $settings_page['url'] }}"><span>{{ $settings_page['label'] }}</span>@if ($settings_page['value'] !== null)<span class="f-form-row__value @if ($settings_page['problem']) mailbox-pages__problem @endif">{{ $settings_page['value'] }}</span>@endif</a>
        @endforeach
        {!! $settings_module_pages !!}
    </x-fruit::form-section>
@endif

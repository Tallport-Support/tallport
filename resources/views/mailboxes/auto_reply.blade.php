@extends('mailboxes/layout_page')

@section('page_width', 'narrow')

@section('title_full', __('Auto Reply').' - '.$mailbox->name)

@section('content')
    @php
        $auto_reply_enabled = (bool) old('auto_reply_enabled', $mailbox->auto_reply_enabled);
        $texts_view = ['languages' => $languages, 'open' => $open, 'focus' => $focus, 'can_translate' => $can_translate];
    @endphp
    <div class="page-content">
        @include('partials/flash_messages')

        {{-- Saved with its button, not as it changes: an auto reply isn't sent half-written. --}}
        <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.auto_reply.save', ['id' => $mailbox->getSlug()]) }}" x-data="tallportAutomaticMessages(@js(route('mailboxes.automatic_messages.translate', ['id' => $mailbox->getSlug()])), @js($texts), {enabled: @js($auto_reply_enabled)})">
            {{-- Enter in a field saves (the form's first submit button), not Remove. --}}
            <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
            {{ csrf_field() }}

            {{-- While it's off, the mailbox's versions can't be changed (and aren't saved). --}}
            <x-fruit::form-section :title="__('Mailbox Auto Reply')" :level="3" :footer="__('Customers get it in their language if it’s here, else Default. Channels set to The Mailbox’s send it.')">
                <x-fruit::field :label="__('Send an Automatic Reply')" layout="row" :description="__('Once, after a customer’s first message in a new conversation.')">
                    <x-fruit::switch id="auto_reply_enabled" name="auto_reply_enabled" value="1" x-model="enabled" :checked="$auto_reply_enabled" />
                </x-fruit::field>
                @include('mailboxes/partials/message_texts', $texts_view + ['group' => 'mailbox', 'base' => 'auto-reply', 'texts' => $groups['mailbox']['texts'], 'subject' => $mailbox->hasEmail(), 'editor' => true, 'show' => null, 'disabled' => '!enabled'])
            </x-fruit::form-section>

            @foreach (array_slice($groups, 1, null, true) as $key => $group)
                @php $channel_mode = old('messages.'.$key.'.mode', $group['mode']); @endphp
                <x-fruit::form-section id="auto-reply-{{ $key }}" :title="$group['label']" :level="3" x-data="{ mode: {{ \Illuminate\Support\Js::from($channel_mode) }} }">
                    {{-- Where the AI agent takes new chats (Mailbox › AI), it says the first word. --}}
                    <x-fruit::field :label="__('Auto Reply')" layout="row" :description="in_array($key, App\Ai\ChatAgent::channels($mailbox), true) ? __('Not sent while the AI takes new chats here.') : null">
                        <x-fruit::select id="auto-reply-{{ $key }}-mode" name="messages[{{ $key }}][mode]" x-model="mode" x-on:change="chooseMode($el, {{ \Illuminate\Support\Js::from($key) }})" ::aria-describedby="mode === 'mailbox' && !enabled ? 'auto-reply-{{ $key }}-mode-note' : null">
                            <option value="mailbox" @selected($channel_mode == 'mailbox')>{{ __('The Mailbox’s') }}</option>
                            <option value="own" @selected($channel_mode == 'own')>{{ __('Its Own') }}</option>
                            <option value="off" @selected($channel_mode == 'off')>{{ __('None') }}</option>
                        </x-fruit::select>
                        {{-- Only while it applies, as on the screen (the switch, the radios, the select). --}}
                        <p class="f-help" id="auto-reply-{{ $key }}-mode-note" x-show="mode === 'mailbox' && !enabled" x-cloak>{{ __('Off for now: the mailbox’s auto reply is off.') }}</p>
                    </x-fruit::field>
                    @include('mailboxes/partials/message_texts', $texts_view + ['group' => $key, 'base' => 'auto-reply-'.$key, 'texts' => $group['texts'], 'subject' => $group['email'], 'editor' => $group['email'], 'show' => "mode === 'own'", 'disabled' => null])
                </x-fruit::form-section>
            @endforeach

            @if (count($groups) == 1)
                <p class="f-help">{{ __('This mailbox has no channels yet. Set one up under Channels.') }}</p>
            @endif
        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

@extends('mailboxes/layout_page')

@section('page_width', 'narrow')

@section('title_full', __('Greeting').' - '.$mailbox->name)

@section('content')
    @php
        $greeting_mode = old('messages.mailbox.mode', $groups['mailbox']['mode']);
        $texts_view = ['languages' => $languages, 'open' => $open, 'can_translate' => $can_translate, 'subject' => false, 'editor' => false, 'disabled' => null];
    @endphp
    <div class="page-content">
        @include('partials/flash_messages')

        {{-- Saved with its button: customers see it, never half-written. --}}
        <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.greeting.save', ['id' => $mailbox->getSlug()]) }}" x-data="tallportAutomaticMessages(@js(route('mailboxes.automatic_messages.translate', ['id' => $mailbox->getSlug()])), @js($texts), {greeting: @js($greeting_mode)})">
            {{-- Enter in a field saves (the form's first submit button), not Remove. --}}
            <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
            {{ csrf_field() }}

            <x-fruit::form-section :title="__('Mailbox Greeting')" :level="3" :footer="__('Shown before the customer writes, on the channels that can: Website Chat, Telegram and Matrix. Email and Nostr can’t.')">
                <x-fruit::fieldset>
                    <legend class="f-sr-only">{{ __('Mailbox Greeting') }}</legend>
                    <x-fruit::radio name="messages[mailbox][mode]" value="tallport" id="greeting-mode-tallport" x-model="greeting" :checked="$greeting_mode == 'tallport'" :description="__('“Hi, how can we help?” in the customer’s language, with their first name when it’s known.')">{{ __('Built-in Greeting') }}</x-fruit::radio>
                    <x-fruit::radio name="messages[mailbox][mode]" value="own" id="greeting-mode-own" x-model="greeting" :checked="$greeting_mode == 'own'">{{ __('Your Own Greeting') }}</x-fruit::radio>
                    <x-fruit::radio name="messages[mailbox][mode]" value="off" id="greeting-mode-off" x-model="greeting" :checked="$greeting_mode == 'off'">{{ __('No Greeting') }}</x-fruit::radio>
                </x-fruit::fieldset>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Your Greeting')" :level="3" x-show="greeting === 'own'">
                @include('mailboxes/partials/message_texts', $texts_view + ['group' => 'mailbox', 'base' => 'greeting', 'texts' => $groups['mailbox']['texts'], 'show' => null])
            </x-fruit::form-section>

            @foreach (array_slice($groups, 1, null, true) as $key => $group)
                @php $channel_mode = old('messages.'.$key.'.mode', $group['mode']); @endphp
                <x-fruit::form-section id="greeting-{{ $key }}" :title="$group['label']" :level="3" x-data="{ mode: {{ \Illuminate\Support\Js::from($channel_mode) }} }" :footer="['webchat' => __('Shown when a visitor opens the chat.'), 'telegram' => __('Sent when the customer taps Start, and shown in the empty chat.'), 'matrix' => __('Sent when the bot joins a direct chat.')][$key] ?? null">
                    <x-fruit::field :label="__('Greeting')" layout="row">
                        <x-fruit::select id="greeting-{{ $key }}-mode" name="messages[{{ $key }}][mode]" x-model="mode" x-on:change="chooseMode($el, {{ \Illuminate\Support\Js::from($key) }})">
                            <option value="mailbox" @selected($channel_mode == 'mailbox')>{{ __('The Mailbox’s') }}</option>
                            <option value="own" @selected($channel_mode == 'own')>{{ __('Its Own') }}</option>
                            <option value="off" @selected($channel_mode == 'off')>{{ __('None') }}</option>
                        </x-fruit::select>
                        <x-slot:description><span x-show="mode === 'mailbox' && greeting === 'off'">{{ __('Off for now: the mailbox has no greeting.') }}</span></x-slot:description>
                    </x-fruit::field>
                    @include('mailboxes/partials/message_texts', $texts_view + ['group' => $key, 'base' => 'greeting-'.$key, 'texts' => $group['texts'], 'show' => "mode === 'own'"])
                </x-fruit::form-section>
            @endforeach

            @if (count($groups) == 1)
                <p class="f-help">{{ __('None of this mailbox’s channels can greet. Website Chat, Telegram and Matrix can, once they’re set up.') }}</p>
            @endif
        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

@extends('mailboxes/layout_page')

@section('page_width', 'narrow')

@section('title_full', __('Website Chat').' - '.$mailbox->name)

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form">
            <p class="f-help">{{ __('A chat button on your websites. Visitors’ messages become conversations in this mailbox, and they see your replies in the chat.') }}</p>

            {{-- Saved with its button: the greeting goes to visitors, never half-written. --}}
            <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.web_chat.save', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}

                <x-fruit::form-section>
                    <x-fruit::field :label="__('Enabled')" layout="row">
                        <x-fruit::switch id="web_chat_enabled" name="enabled" value="1" :checked="(bool) old('enabled', $settings['enabled'])" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Websites')" :description="__('The websites the chat may be on, one per line, such as https://www.example.com.')">
                        <x-fruit::textarea id="web_chat_origins" name="origins" rows="3" placeholder="https://www.example.com">{{ old('origins', implode("\n", $settings['origins'])) }}</x-fruit::textarea>
                    </x-fruit::field>
                </x-fruit::form-section>

                <x-fruit::form-section :title="__('What Visitors See')" :level="3">
                    <x-fruit::field :label="__('Greeting')" :description="__('The first message in the chat, from the team. Leave it empty for none.')">
                        <x-fruit::textarea id="web_chat_greeting" name="greeting" rows="2" maxlength="1000">{{ old('greeting', $settings['greeting']) }}</x-fruit::textarea>
                    </x-fruit::field>

                    <x-fruit::field :label="__('Reply Time')" :description="__('Below the mailbox’s name, such as “We usually reply within an hour”. Leave it empty for none.')" layout="row">
                        <x-fruit::input id="web_chat_reply_time" name="reply_time" maxlength="100" :value="old('reply_time', $settings['reply_time'])" />
                    </x-fruit::field>

                    <div class="f-form-row">
                        <span>{{ __('Accent Color') }}</span>
                        <x-fruit::accent-picker name="accent" :value="old('accent', $settings['accent'])" />
                    </div>
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                </footer>
            </form>

            @if ($snippet)
                <x-fruit::form-section :title="__('On Your Website')" :level="3" :footer="__('Add the code to every page, before the closing body tag. For visitors without JavaScript, link to the chat itself.')">
                    <div class="f-form-row">
                        <pre>{{ $snippet }}</pre>
                        <x-fruit::copy-button size="small" :value="$snippet">{{ __('Copy') }}</x-fruit::copy-button>
                    </div>
                    <div class="f-form-row">
                        <a href="{{ $link }}" target="_blank" rel="noopener">{{ $link }}</a>
                        <x-fruit::copy-button size="small" :value="$link">{{ __('Copy') }}</x-fruit::copy-button>
                    </div>
                </x-fruit::form-section>
            @endif
        </div>
    </div>
@endsection

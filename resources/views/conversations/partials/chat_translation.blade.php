{{-- A translated chat's composer (livewire/conversation-composer; tallportComposer in public/js/conversations.js):
     the language replies go out in, and the reply's translation before it's sent. --}}
@php
    $chat_language = App\Ai\ChatTranslation::customerLanguage($conversation);
@endphp
<div class="conv-chat-translation">
    <label class="conv-chat-translation__language">
        <x-icon.languages class="f-icon" aria-hidden="true" />
        <span>{{ __('Translate Replies Into') }}</span>
        <select class="f-input" wire:change="setCustomerLanguage($event.target.value)">
            <option value="" @selected(!$chat_language)>{{ __('Not Translated') }}</option>
            @foreach (App\Ai\Settings::displayNames() as $code => $name)
                <option value="{{ $code }}" @selected($chat_language === $code)>{{ App\Ai\Settings::optionName($code) }}</option>
            @endforeach
        </select>
    </label>

    <div aria-live="polite">
        <p class="f-help conv-translation-busy" wire:loading.flex wire:target="previewTranslation"><x-fruit::spinner /> {{ __('Translating…') }}</p>
        @if ($translation)
            @if ($translation['error'])
                <x-fruit::alert tone="warning" class="conv-translation-preview">
                    {{ __('The reply could not be translated: :error', ['error' => $translation['error']]) }}
                    <x-slot:actions>
                        <x-fruit::button size="small" x-on:click="retryTranslation()">{{ __('Retry') }}</x-fruit::button>
                        <x-fruit::button size="small" variant="primary" x-on:click="sendAsWritten()">{{ __('Send as Written') }}</x-fruit::button>
                    </x-slot:actions>
                </x-fruit::alert>
            @else
                <section class="conv-translation-preview conv-translation-preview--ready" aria-label="{{ __('Translation') }}">
                    <p class="conv-translation-preview__label">{{ __('Sent in :language', ['language' => App\Ai\Settings::displayName($chat_language)]) }}</p>
                    <div class="conv-translation-preview__text f-prose" dir="auto">{!! safe_raw_html($translation['html']) !!}</div>
                    <div class="conv-translation-preview__actions">
                        <x-fruit::button size="small" variant="ghost" x-on:click="editTranslation()">{{ __('Edit') }}</x-fruit::button>
                        <x-fruit::button size="small" variant="ghost" x-on:click="sendAsWritten()">{{ __('Send as Written') }}</x-fruit::button>
                        <x-fruit::button size="small" variant="primary" x-on:click="sendPreview()">{{ __('Send Translation') }}</x-fruit::button>
                    </div>
                </section>
            @endif
        @endif
    </div>
</div>

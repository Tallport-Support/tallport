@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('AI Assistant').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    @php
        $ai_features_on = collect(App\Ai\Settings::FEATURES)->mapWithKeys(fn ($feature) => [$feature => App\Ai\Settings::enabled($feature, $mailbox)]);
        $ai_chat = App\Ai\Settings::chatTranslation($mailbox);
        $ai_language = ((array) Option::get('aiassistant.mailbox_language', []))[$mailbox->id] ?? '';
    @endphp
    <div class="page-content">
        @include('partials/flash_messages')

        @unless ($configured)
            <x-fruit::alert tone="info">
                {{ __('The AI Assistant isn\'t set up yet.') }}
                <x-slot:actions><a wire:navigate class="f-button f-button--small" href="{{ route('settings', ['section' => 'ai']) }}">{{ __('Set Up the AI Assistant') }}</a></x-slot:actions>
            </x-fruit::alert>
        @endunless

        {{-- A mailbox's AI Assistant (MailboxesController::ai()); the installation's providers, models and limits are in Settings › AI Assistant. --}}
        <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.ai.save', ['id' => $mailbox->id]) }}" x-data="{ drafts: @js($ai_features_on['drafts']), chat: @js($ai_chat) }">
            {{ csrf_field() }}
            <fieldset class="mailbox-ai" @disabled(!$configured)>
                <x-fruit::form-section :title="__('Features')">
                    <x-fruit::field :label="__('Summaries')" :description="__('A short summary at the top of each conversation.')" layout="row">
                        <x-fruit::switch name="features[summaries]" value="1" :checked="$ai_features_on['summaries']" />
                    </x-fruit::field>
                    <x-fruit::field :label="__('Translations')" :description="__('Customer messages in another language, translated for agents.')" layout="row">
                        <x-fruit::switch name="features[translations]" value="1" :checked="$ai_features_on['translations']" />
                    </x-fruit::field>
                    <x-fruit::field :label="__('Drafts')" :description="__('Reply drafts for agents to review and send.')" layout="row">
                        <x-fruit::switch name="features[drafts]" value="1" :checked="$ai_features_on['drafts']" x-on:change="drafts = $el.checked" />
                    </x-fruit::field>
                </x-fruit::form-section>

                <x-fruit::form-section :title="__('Language')">
                    <x-fruit::field :label="__('Language')" :description="__('Summaries and translations use this language, unless an agent has chosen their own.')" layout="row">
                        <x-fruit::select name="language">
                            <option value="">{{ __('Default (:language)', ['language' => App\Ai\Settings::displayName(App\Ai\Settings::defaultLanguage())]) }}</option>
                            @foreach (App\Ai\Settings::displayNames() as $code => $name)
                                <option value="{{ $code }}" @selected(old('language', $ai_language) === $code)>{{ App\Ai\Settings::optionName($code) }}</option>
                            @endforeach
                        </x-fruit::select>
                    </x-fruit::field>
                    <x-fruit::field :label="__('Glossary')" :description="__('One term per line: kept as written, or translated a fixed way (term = translation).')">
                        <x-fruit::textarea name="glossary" rows="4" maxlength="3000" placeholder="12VPX&#10;server = Server">{{ old('glossary', App\Ai\Settings::glossary($mailbox)) }}</x-fruit::textarea>
                    </x-fruit::field>
                </x-fruit::form-section>

                <x-fruit::form-section :title="__('Chat Translation')">
                    <x-fruit::field :label="__('Translate Chats')" :description="__('Telegram and Nostr chats, both ways: agents read and write in their own language, and see a reply\'s translation before it\'s sent.')" layout="row">
                        <x-fruit::switch name="chat_translation" value="1" :checked="$ai_chat" x-on:change="chat = $el.checked" />
                    </x-fruit::field>
                    <x-fruit::checkbox name="translation_note" value="1" :checked="App\Ai\Settings::translationNote($mailbox)" x-bind:disabled="!chat" :description="__('Chat replies sent translated end with “Translated automatically”, in the customer\'s language.')">{{ __('Mark Translated Replies') }}</x-fruit::checkbox>
                </x-fruit::form-section>

                {{-- Only drafts use it: off while Drafts is. --}}
                <fieldset class="mailbox-ai__context" x-bind:disabled="!drafts" x-data="tallportAiContextTest({{ $mailbox->id }})">
                    <x-fruit::form-section :title="__('Customer Context')">
                        <x-fruit::field :label="__('URL')" layout="row">
                            <x-fruit::input type="url" class="ai-context-url" name="customer_context_url" :value="old('customer_context_url', $context['url'])" maxlength="2048" placeholder="https://example.com/customer-context" />
                        </x-fruit::field>
                        <x-fruit::field :label="__('Secret Key')" layout="row">
                            <x-fruit::input type="password" class="ai-context-secret" name="customer_context_secret_key" :value="\Helper::safePassword($context['secret_key'])" maxlength="255" autocomplete="new-password" />
                        </x-fruit::field>
                        <x-fruit::field :label="__('Signature Header')" layout="row">
                            <x-fruit::select class="ai-context-header" name="customer_context_signature_header">
                                @foreach (App\Ai\CustomerContext::HEADERS as $header)
                                    <option value="{{ $header }}" @selected($context['signature_header'] == $header)>{{ $header }}</option>
                                @endforeach
                            </x-fruit::select>
                        </x-fruit::field>
                        <x-fruit::field :label="__('Reply Guidance')" :description="__('How replies should sound, and what to avoid. Up to 6,000 characters.')">
                            <x-fruit::textarea name="customer_context_guidance" rows="5" maxlength="6000">{{ old('customer_context_guidance', $context['guidance']) }}</x-fruit::textarea>
                        </x-fruit::field>
                        <x-fruit::field :label="__('Test')" :description="__('Asks the URL about this customer and shows the answer.')" control-id="ai_context_test">
                            <div class="f-input-group">
                                <input id="ai_context_test" type="email" class="f-input ai-context-test-email" placeholder="{{ __('Customer email address') }}" data-required="{{ __('Enter a customer\'s email address.') }}" x-on:input="$el.setCustomValidity('')" x-on:blur="$el.setCustomValidity('')">
                                <button type="button" class="f-button ai-context-test" x-on:click="test">{{ __('Test') }}</button>
                            </div>
                        </x-fruit::field>
                        {{-- The answer: a status line (announced), then the response, an error's under Show Response. --}}
                        <div class="ai-context-test-result">
                            <p class="ai-context-test-status" role="status" x-bind:class="result && !result.ok && 'ai-context-test-status--error'"
                                data-success="{{ __(':status · :size in :time ms') }}" data-failure="{{ __('HTTP :status · The URL answered with an error.') }}">
                                <template x-if="result"><span class="ai-context-test-status__line">
                                    <x-icon.circle-check class="f-icon" aria-hidden="true" x-show="result.ok" />
                                    <x-icon.triangle-alert class="f-icon" aria-hidden="true" x-show="!result.ok" />
                                    <span x-text="result.line"></span>
                                </span></template>
                            </p>
                            <template x-if="result && result.body">
                                <details class="ai-context-test-response" x-bind:open="result.ok">
                                    <summary>{{ __('Show Response') }}</summary>
                                    <pre tabindex="0" aria-label="{{ __('Test result') }}" x-text="result.body"></pre>
                                </details>
                            </template>
                        </div>
                    </x-fruit::form-section>
                    <p class="f-form-section__footer mailbox-ai__context-footer" x-text="drafts ? @js(__('When drafting a reply, the assistant asks this URL about the customer, signed with the key, and uses the answer.')) : @js(__('Used when drafting replies. Turn on Drafts to use it.'))"></p>
                </fieldset>
            </fieldset>
        </form>
    </div>
@endsection

@if ($configured)
    @section('page_footer')
        <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
    @endsection
@endif

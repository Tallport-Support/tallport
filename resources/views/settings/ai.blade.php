<form class="settings-form" method="POST" action="" id="ai_settings_form">
    {{ csrf_field() }}

    {{-- The providers set up (App\Ai\Settings::providers()); a new one added with Add Provider. --}}
    <x-fruit::form-section :title="__('Providers')" :footer="__('API keys: leave the masked value unchanged to keep the current key. Some local providers do not require a key.')">
        @foreach ($ai_providers as $provider_id => $ai_provider)
            <x-fruit::disclosure class="ai-provider-block" :title="App\Ai\Providers::label($ai_provider)">
                @include('settings/partials/ai_provider', ['field' => 'settings[aiassistant.providers]['.$provider_id.']', 'ai_provider' => $ai_provider])
                <x-fruit::checkbox name="settings[aiassistant.providers][{{ $provider_id }}][remove]" value="1" :description="__('Features using it switch to the first provider.')">{{ __('Remove This Provider') }}</x-fruit::checkbox>
            </x-fruit::disclosure>
        @endforeach
        <div x-data="{ adding: false }">
            <x-fruit::button size="small" x-show="!adding" x-on:click="adding = true; $nextTick(() => $root.querySelector('select')?.focus())">{{ __('Add Provider') }}</x-fruit::button>
            <template x-if="adding">
                <div class="ai-provider-new">
                    @include('settings/partials/ai_provider', ['field' => 'settings[aiassistant.providers][new]', 'ai_provider' => ['provider' => 'openai', 'api_key' => '', 'base_url' => '']])
                </div>
            </template>
        </div>
    </x-fruit::form-section>

    {{-- Each feature's model: the primary, and a backup tried when the primary fails. --}}
    <x-fruit::form-section :title="__('Models')" :footer="__('When the primary model fails (unreachable, out of credit, a wrong key or model), the backup is used.')">
        <div>
            <x-fruit::table class="ai-models">
                <thead>
                    <tr>
                        <th>{{ __('Feature') }}</th>
                        <th>{{ __('Primary Model') }}</th>
                        <th>{{ __('Backup Model') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ai_features as $feature => $feature_name)
                        @php $feature_models = App\Ai\Settings::featureModels($feature); @endphp
                        <tr>
                            <td>{{ $feature_name }}</td>
                            @foreach (['primary', 'backup'] as $slot)
                                <td>
                                    <div class="ai-model-choice">
                                        <x-fruit::select name="settings[aiassistant.models][{{ $feature }}][{{ $slot }}][provider]" :aria-label="$feature_name.': '.($slot == 'primary' ? __('Primary Model') : __('Backup Model')).' · '.__('Provider')">
                                            @if ($slot == 'backup')
                                                <option value="">{{ __('None') }}</option>
                                            @endif
                                            @foreach ($ai_providers as $provider_id => $ai_provider)
                                                <option value="{{ $provider_id }}" @selected(($feature_models[$slot][0] ?? '') === $provider_id)>{{ App\Ai\Providers::label($ai_provider) }}</option>
                                            @endforeach
                                        </x-fruit::select>
                                        <x-fruit::input name="settings[aiassistant.models][{{ $feature }}][{{ $slot }}][model]" :value="$feature_models[$slot][1] ?? ''" maxlength="255" :aria-label="$feature_name.': '.($slot == 'primary' ? __('Primary Model') : __('Backup Model'))" :placeholder="$slot == 'primary' ? App\Ai\Settings::DEFAULT_MODEL : ''" />
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-fruit::table>
        </div>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Documentation')">
        <div class="f-form-row">
            <a class="f-button" href="{{ route('ai.documents') }}">{{ __('Manage Documentation') }}</a>
        </div>

        @if (!App\Ai\Settings::embeddingsAvailable())
            <x-fruit::alert tone="warning">{{ __('The selected embedding provider does not support embeddings, so drafts are made without documentation. Summaries and translations are not affected.') }}</x-fruit::alert>
        @endif

        <x-fruit::field :label="__('Embedding Provider')" layout="row">
            <x-fruit::select name="settings[aiassistant.documentation.embedding_provider]" class="ai-provider" id="ai_embedding_provider" data-base-url="#ai_embedding_base_url" x-data x-on:change="document.querySelector($el.dataset.baseUrl).placeholder = $el.selectedOptions[0].dataset.baseUrl">
                <option value="same" data-base-url="" @selected($settings['aiassistant.documentation.embedding_provider'] == 'same')>{{ __('Same as AI Provider') }}</option>
                @foreach (App\Ai\Providers::PRESETS as $provider => $preset)
                    @if ($preset['embedding_model'])
                        <option value="{{ $provider }}" data-base-url="{{ $preset['base_url'] }}" @selected($settings['aiassistant.documentation.embedding_provider'] == $provider)>{{ $preset['name'] }}</option>
                    @endif
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Embedding API Key')" :description="__('Leave blank when reusing the AI provider key. Leave the masked value unchanged to keep the current key.')" layout="row">
            <x-fruit::input type="password" id="ai_embedding_api_key" name="settings[aiassistant.documentation.embedding_api_key]" :value="\Helper::safePassword($settings['aiassistant.documentation.embedding_api_key'])" autocomplete="new-password" />
        </x-fruit::field>

        <x-fruit::field :label="__('Embedding Base URL')" :description="__('Optional. Leave blank to use the selected embedding provider default.')" layout="row">
            <x-fruit::input type="url" id="ai_embedding_base_url" name="settings[aiassistant.documentation.embedding_base_url]" :value="old('settings.aiassistant.documentation.embedding_base_url', $settings['aiassistant.documentation.embedding_base_url'])" :placeholder="App\Ai\Settings::embeddingProviderIsSame() ? '' : App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider()]['base_url']" />
        </x-fruit::field>

        <x-fruit::field :label="__('Embedding Model')" layout="row">
            <x-fruit::input id="ai_embedding_model" name="settings[aiassistant.documentation.embedding_model]" :value="$settings['aiassistant.documentation.embedding_model']" maxlength="255" :placeholder="App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider()]['embedding_model']" />
        </x-fruit::field>

        <x-fruit::field :label="__('Chunk Size')" layout="row">
            <x-fruit::number id="ai_chunk_size" name="settings[aiassistant.documentation.chunk_size]" :value="$settings['aiassistant.documentation.chunk_size']" min="500" max="20000" />
        </x-fruit::field>

        <x-fruit::field :label="__('Chunk Overlap')" layout="row">
            <x-fruit::number id="ai_chunk_overlap" name="settings[aiassistant.documentation.chunk_overlap]" :value="$settings['aiassistant.documentation.chunk_overlap']" min="0" max="5000" />
        </x-fruit::field>

        <x-fruit::field :label="__('Retrieval Limit')" layout="row">
            <x-fruit::number id="ai_retrieval_limit" name="settings[aiassistant.documentation.retrieval_limit]" :value="$settings['aiassistant.documentation.retrieval_limit']" min="1" max="20" />
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Conversations')">
        <x-fruit::field :label="__('Language')" :description="__('Summaries and translations are in this language, unless a mailbox or user has its own.')" layout="row">
            <x-fruit::select name="settings[aiassistant.translation_language]" id="ai_language">
                @foreach ($ai_languages as $code => $name)
                    <option value="{{ $code }}" @selected($settings['aiassistant.translation_language'] == $code)>{{ App\Ai\Settings::optionName($code) }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Summary Start')" :description="__('Summarize conversations with more messages than this.')" layout="row">
            <x-fruit::number id="ai_summary_threshold" name="settings[aiassistant.summary_conversation_threshold]" :value="$settings['aiassistant.summary_conversation_threshold']" min="0" max="10" />
        </x-fruit::field>

        <x-fruit::field :label="__('Drafts Per Day')" :description="__('Reply drafts each user can make per day, unless the user has their own limit. 0 turns drafting off.')" layout="row">
            <x-fruit::number id="ai_drafts_per_day" name="settings[aiassistant.drafts_per_day]" :value="old('settings.aiassistant.drafts_per_day', $settings['aiassistant.drafts_per_day'])" min="0" max="10000" />
        </x-fruit::field>

        <x-fruit::field :label="__('Daily Tokens Per Mailbox')" :description="__('Tokens each mailbox may use per day, for all AI features. When they are used up, the AI Assistant is unavailable in that mailbox until tomorrow. 0: no limit.')" layout="row">
            <x-fruit::number id="ai_daily_tokens" name="settings[aiassistant.daily_tokens]" :value="old('settings.aiassistant.daily_tokens', $settings['aiassistant.daily_tokens'])" min="0" max="1000000000" />
        </x-fruit::field>

        <x-fruit::field :label="__('Translations Per Customer Per Hour')" :description="__('Messages of one customer translated per hour, against floods; more are shown untranslated. 0: no limit.')" layout="row">
            <x-fruit::number id="ai_translations_per_customer_hour" name="settings[aiassistant.translations_per_customer_hour]" :value="old('settings.aiassistant.translations_per_customer_hour', $settings['aiassistant.translations_per_customer_hour'])" min="0" max="10000" />
        </x-fruit::field>
    </x-fruit::form-section>

    @if (count($ai_mailboxes))
        <x-fruit::form-section :title="__('Mailboxes')">
            <div>
                <x-fruit::table class="ai-mailboxes">
                    <thead>
                        <tr>
                            <th>{{ __('Mailbox') }}</th>
                            <th>{{ __('Language') }}</th>
                            <th>{{ __('Summaries') }}</th>
                            <th>{{ __('Translations') }}</th>
                            <th>{{ __('Drafts') }}</th>
                            <th>{{ __('Chat Translation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ai_mailboxes as $mailbox)
                            <tr>
                                <td>{{ $mailbox->name }}</td>
                                <td>
                                    <x-fruit::select name="settings[aiassistant.mailbox_language][{{ $mailbox->id }}]" :aria-label="__('Language')">
                                        <option value="">{{ __('Default') }}</option>
                                        @foreach ($ai_languages as $code => $name)
                                            <option value="{{ $code }}" @selected(($settings['aiassistant.mailbox_language'][$mailbox->id] ?? '') == $code)>{{ App\Ai\Settings::optionName($code) }}</option>
                                        @endforeach
                                    </x-fruit::select>
                                </td>
                                @foreach (array_combine(App\Ai\Settings::FEATURES, [__('Summaries'), __('Translations'), __('Drafts')]) as $feature => $feature_name)
                                    <td>
                                        <x-fruit::checkbox name="settings[aiassistant.mailbox_features_on][{{ $mailbox->id }}][{{ $feature }}]" value="1" :checked="App\Ai\Settings::enabled($feature, $mailbox)" :aria-label="$mailbox->name.': '.$feature_name" />
                                    </td>
                                @endforeach
                                {{-- Chats both ways: the agent reads and writes in their language (off by default). --}}
                                <td>
                                    <x-fruit::checkbox name="settings[aiassistant.mailbox_chat_translation][{{ $mailbox->id }}]" value="1" :checked="App\Ai\Settings::chatTranslation($mailbox)" :aria-label="$mailbox->name.': '.__('Chat Translation')" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            </div>
        </x-fruit::form-section>

        {{-- Per mailbox: terms for every translation, and whether translated chat replies say so. --}}
        <x-fruit::form-section :title="__('Translation')">
            @foreach ($ai_mailboxes as $mailbox)
                @php
                    $ai_glossary = old('settings.aiassistant.translation_glossary.'.$mailbox->id, App\Ai\Settings::glossary($mailbox));
                @endphp
                <x-fruit::disclosure class="ai-translation" :title="$mailbox->name" :open="$errors->has('settings.aiassistant.translation_glossary.'.$mailbox->id)">
                    <div class="settings-form">
                        <x-fruit::field :label="__('Glossary')" :description="__('Terms the AI Assistant keeps as they are, or translates a certain way, one per line: a product name, or server = Server.')">
                            <x-fruit::textarea id="ai_glossary_{{ $mailbox->id }}" name="settings[aiassistant.translation_glossary][{{ $mailbox->id }}]" rows="4" maxlength="3000">{{ $ai_glossary }}</x-fruit::textarea>
                        </x-fruit::field>
                        <x-fruit::checkbox name="settings[aiassistant.mailbox_translation_note][{{ $mailbox->id }}]" value="1" :checked="App\Ai\Settings::translationNote($mailbox)" :description="__('Chat replies sent translated end with “Translated automatically”, in the customer\'s language.')">{{ __('Mark Translated Replies') }}</x-fruit::checkbox>
                    </div>
                </x-fruit::disclosure>
            @endforeach
        </x-fruit::form-section>

        <x-fruit::form-section :title="__('Customer Context')" :footer="__('Optional, per mailbox: a URL that is sent the customer\'s email addresses when a reply is drafted, and returns JSON about the customer. Requests are signed with the secret key.')">
            @foreach ($ai_mailboxes as $mailbox)
                @php
                    $ai_context = App\Ai\CustomerContext::settings($mailbox);
                @endphp
                <x-fruit::disclosure class="ai-customer-context" data-mailbox-id="{{ $mailbox->id }}" x-data="tallportAiContextTest({{ $mailbox->id }})" :title="$mailbox->name.($ai_context['url'] ? ' · '.$ai_context['url'] : '')" :open="$errors->has('settings.aiassistant.customer_context_url.'.$mailbox->id) || $errors->has('settings.aiassistant.customer_context_guidance.'.$mailbox->id)">
                    <div class="settings-form">
                        <x-fruit::field :label="__('URL')">
                            <x-fruit::input type="url" id="ai_context_url_{{ $mailbox->id }}" class="ai-context-url" name="settings[aiassistant.customer_context_url][{{ $mailbox->id }}]" :value="old('settings.aiassistant.customer_context_url.'.$mailbox->id, $ai_context['url'])" maxlength="2048" placeholder="https://example.com/customer-context" />
                        </x-fruit::field>
                        <x-fruit::field :label="__('Secret Key')">
                            <x-fruit::input type="password" id="ai_context_secret_{{ $mailbox->id }}" class="ai-context-secret" name="settings[aiassistant.customer_context_secret_key][{{ $mailbox->id }}]" :value="\Helper::safePassword($ai_context['secret_key'])" maxlength="255" autocomplete="new-password" />
                        </x-fruit::field>
                        <x-fruit::field :label="__('Signature Header')">
                            <x-fruit::select id="ai_context_header_{{ $mailbox->id }}" class="ai-context-header" name="settings[aiassistant.customer_context_signature_header][{{ $mailbox->id }}]">
                                @foreach (App\Ai\CustomerContext::HEADERS as $header)
                                    <option value="{{ $header }}" @selected($ai_context['signature_header'] == $header)>{{ $header }}</option>
                                @endforeach
                            </x-fruit::select>
                        </x-fruit::field>
                        <x-fruit::field :label="__('Reply Guidance')" :description="__('Optional background for drafting replies: who you are, what customers buy, terminology, what fields in the customer context mean, and the reply style.')">
                            <x-fruit::textarea id="ai_context_guidance_{{ $mailbox->id }}" name="settings[aiassistant.customer_context_guidance][{{ $mailbox->id }}]" rows="5" maxlength="6000">{{ old('settings.aiassistant.customer_context_guidance.'.$mailbox->id, $ai_context['guidance']) }}</x-fruit::textarea>
                        </x-fruit::field>
                        <x-fruit::field :label="__('Test')" control-id="ai_context_test_{{ $mailbox->id }}">
                            <div class="f-input-group">
                                <input id="ai_context_test_{{ $mailbox->id }}" type="email" class="f-input ai-context-test-email" placeholder="{{ __('Customer email address') }}">
                                <button type="button" class="f-button ai-context-test" x-on:click="test">{{ __('Test') }}</button>
                            </div>
                        </x-fruit::field>
                        <pre class="ai-context-test-result" x-show="result" x-text="result" x-cloak></pre>
                    </div>
                </x-fruit::disclosure>
            @endforeach
        </x-fruit::form-section>
    @endif

</form>

@section('page_footer')
    <x-fruit::button type="submit" form="ai_settings_form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

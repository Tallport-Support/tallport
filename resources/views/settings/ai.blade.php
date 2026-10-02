<form class="form-horizontal margin-top" method="POST" action="" id="ai_settings_form">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('Provider') }}</h3>

    <div class="form-group margin-top">
        <label for="ai_provider" class="col-sm-2 control-label">{{ __('Provider') }}</label>
        <div class="col-sm-6">
            <select name="settings[aiassistant.provider]" class="form-control input-sized ai-provider" id="ai_provider" data-base-url="#ai_base_url">
                @foreach (App\Ai\Providers::PRESETS as $provider => $preset)
                    <option value="{{ $provider }}" data-base-url="{{ $preset['base_url'] }}" @if ($settings['aiassistant.provider'] == $provider) selected @endif>{{ $preset['name'] }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-group">
        <label for="ai_api_key" class="col-sm-2 control-label">{{ __('API Key') }}</label>
        <div class="col-sm-6">
            <input id="ai_api_key" type="password" class="form-control input-sized" name="settings[aiassistant.api_key]" value="{{ \Helper::safePassword($settings['aiassistant.api_key']) }}" autocomplete="new-password">
            <div class="form-help">{{ __('Leave the masked value unchanged to keep the current key. Some local providers do not require a key.') }}</div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.aiassistant.base_url') ? ' has-error' : '' }}">
        <label for="ai_base_url" class="col-sm-2 control-label">{{ __('Base URL') }}</label>
        <div class="col-sm-6">
            <input id="ai_base_url" type="url" class="form-control input-sized" name="settings[aiassistant.base_url]" value="{{ old('settings.aiassistant.base_url', $settings['aiassistant.base_url']) }}" placeholder="{{ App\Ai\Providers::PRESETS[$settings['aiassistant.provider']]['base_url'] }}">
            <div class="form-help">{{ __('Optional. Leave blank to use the selected provider default.') }}</div>
            @include('partials/field_error', ['field' => 'settings.aiassistant.base_url'])
        </div>
    </div>

    <div class="form-group">
        <label for="ai_model" class="col-sm-2 control-label">{{ __('Model') }}</label>
        <div class="col-sm-6">
            <input id="ai_model" type="text" class="form-control input-sized" name="settings[aiassistant.model]" value="{{ $settings['aiassistant.model'] }}" maxlength="255" placeholder="{{ App\Ai\Settings::DEFAULT_MODEL }}">
            <div class="form-help">{{ __('Enter the model identifier from the selected provider.') }}</div>
        </div>
    </div>

    <h3 class="subheader">{{ __('Documentation') }}</h3>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <a href="{{ route('ai.documents') }}" class="btn btn-default">{{ __('Manage Documentation') }}</a>
        </div>
    </div>

    @if (!App\Ai\Settings::embeddingsAvailable())
        <div class="form-group">
            <div class="col-sm-8 col-sm-offset-2">
                <div class="alert alert-warning margin-bottom-0">
                    {{ __('The selected embedding provider does not support embeddings, so drafts are made without documentation. Summaries and translations are not affected.') }}
                </div>
            </div>
        </div>
    @endif

    <div class="form-group">
        <label for="ai_embedding_provider" class="col-sm-2 control-label">{{ __('Embedding Provider') }}</label>
        <div class="col-sm-6">
            <select name="settings[aiassistant.documentation.embedding_provider]" class="form-control input-sized ai-provider" id="ai_embedding_provider" data-base-url="#ai_embedding_base_url">
                <option value="same" data-base-url="" @if ($settings['aiassistant.documentation.embedding_provider'] == 'same') selected @endif>{{ __('Same as AI Provider') }}</option>
                @foreach (App\Ai\Providers::PRESETS as $provider => $preset)
                    @if ($preset['embedding_model'])
                        <option value="{{ $provider }}" data-base-url="{{ $preset['base_url'] }}" @if ($settings['aiassistant.documentation.embedding_provider'] == $provider) selected @endif>{{ $preset['name'] }}</option>
                    @endif
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-group">
        <label for="ai_embedding_api_key" class="col-sm-2 control-label">{{ __('Embedding API Key') }}</label>
        <div class="col-sm-6">
            <input id="ai_embedding_api_key" type="password" class="form-control input-sized" name="settings[aiassistant.documentation.embedding_api_key]" value="{{ \Helper::safePassword($settings['aiassistant.documentation.embedding_api_key']) }}" autocomplete="new-password">
            <div class="form-help">{{ __('Leave blank when reusing the AI provider key. Leave the masked value unchanged to keep the current key.') }}</div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.aiassistant.documentation.embedding_base_url') ? ' has-error' : '' }}">
        <label for="ai_embedding_base_url" class="col-sm-2 control-label">{{ __('Embedding Base URL') }}</label>
        <div class="col-sm-6">
            <input id="ai_embedding_base_url" type="url" class="form-control input-sized" name="settings[aiassistant.documentation.embedding_base_url]" value="{{ old('settings.aiassistant.documentation.embedding_base_url', $settings['aiassistant.documentation.embedding_base_url']) }}" placeholder="{{ App\Ai\Settings::embeddingProviderIsSame() ? '' : App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider()]['base_url'] }}">
            <div class="form-help">{{ __('Optional. Leave blank to use the selected embedding provider default.') }}</div>
            @include('partials/field_error', ['field' => 'settings.aiassistant.documentation.embedding_base_url'])
        </div>
    </div>

    <div class="form-group">
        <label for="ai_embedding_model" class="col-sm-2 control-label">{{ __('Embedding Model') }}</label>
        <div class="col-sm-6">
            <input id="ai_embedding_model" type="text" class="form-control input-sized" name="settings[aiassistant.documentation.embedding_model]" value="{{ $settings['aiassistant.documentation.embedding_model'] }}" maxlength="255" placeholder="{{ App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider()]['embedding_model'] }}">
        </div>
    </div>

    <div class="form-group">
        <label for="ai_chunk_size" class="col-sm-2 control-label">{{ __('Chunk Size') }}</label>
        <div class="col-sm-6">
            <input id="ai_chunk_size" type="number" class="form-control input-sized" name="settings[aiassistant.documentation.chunk_size]" value="{{ $settings['aiassistant.documentation.chunk_size'] }}" min="500" max="20000">
        </div>
    </div>

    <div class="form-group">
        <label for="ai_chunk_overlap" class="col-sm-2 control-label">{{ __('Chunk Overlap') }}</label>
        <div class="col-sm-6">
            <input id="ai_chunk_overlap" type="number" class="form-control input-sized" name="settings[aiassistant.documentation.chunk_overlap]" value="{{ $settings['aiassistant.documentation.chunk_overlap'] }}" min="0" max="5000">
        </div>
    </div>

    <div class="form-group">
        <label for="ai_retrieval_limit" class="col-sm-2 control-label">{{ __('Retrieval Limit') }}</label>
        <div class="col-sm-6">
            <input id="ai_retrieval_limit" type="number" class="form-control input-sized" name="settings[aiassistant.documentation.retrieval_limit]" value="{{ $settings['aiassistant.documentation.retrieval_limit'] }}" min="1" max="20">
        </div>
    </div>

    <h3 class="subheader">{{ __('Conversations') }}</h3>

    <div class="form-group margin-top">
        <label for="ai_language" class="col-sm-2 control-label">{{ __('Language') }}</label>
        <div class="col-sm-6">
            <select name="settings[aiassistant.translation_language]" class="form-control input-sized" id="ai_language">
                @foreach ($ai_languages as $code => $name)
                    <option value="{{ $code }}" @if ($settings['aiassistant.translation_language'] == $code) selected @endif>{{ $name }}</option>
                @endforeach
            </select>
            <div class="form-help">{{ __('Summaries and translations are in this language, unless a mailbox or user has its own.') }}</div>
        </div>
    </div>

    <div class="form-group">
        <label for="ai_summary_threshold" class="col-sm-2 control-label">{{ __('Summary Start') }}</label>
        <div class="col-sm-6">
            <input id="ai_summary_threshold" type="number" class="form-control input-sized" name="settings[aiassistant.summary_conversation_threshold]" value="{{ $settings['aiassistant.summary_conversation_threshold'] }}" min="0" max="10">
            <div class="form-help">{{ __('Summarize conversations with more messages than this.') }}</div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.aiassistant.drafts_per_day') ? ' has-error' : '' }}">
        <label for="ai_drafts_per_day" class="col-sm-2 control-label">{{ __('Drafts Per Day') }}</label>
        <div class="col-sm-6">
            <input id="ai_drafts_per_day" type="number" class="form-control input-sized" name="settings[aiassistant.drafts_per_day]" value="{{ old('settings.aiassistant.drafts_per_day', $settings['aiassistant.drafts_per_day']) }}" min="0" max="10000">
            <div class="form-help">{{ __('Reply drafts each user can make per day, unless the user has their own limit. 0 turns drafting off.') }}</div>
            @include('partials/field_error', ['field' => 'settings.aiassistant.drafts_per_day'])
        </div>
    </div>

    @if (count($ai_mailboxes))
        <h3 class="subheader">{{ __('Mailboxes') }}</h3>

        <div class="form-group">
            <div class="col-sm-10 col-sm-offset-2">
                <table class="table table-condensed ai-mailboxes">
                    <tr>
                        <th>{{ __('Mailbox') }}</th>
                        <th>{{ __('Language') }}</th>
                        <th class="text-center">{{ __('Summaries') }}</th>
                        <th class="text-center">{{ __('Translations') }}</th>
                        <th class="text-center">{{ __('Drafts') }}</th>
                    </tr>
                    @foreach ($ai_mailboxes as $mailbox)
                        <tr>
                            <td>{{ $mailbox->name }}</td>
                            <td>
                                <select name="settings[aiassistant.mailbox_language][{{ $mailbox->id }}]" class="form-control input-sm" aria-label="{{ __('Language') }}">
                                    <option value="">{{ __('Default') }}</option>
                                    @foreach ($ai_languages as $code => $name)
                                        <option value="{{ $code }}" @if (($settings['aiassistant.mailbox_language'][$mailbox->id] ?? '') == $code) selected @endif>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            @foreach (App\Ai\Settings::FEATURES as $feature)
                                <td class="text-center">
                                    <input type="checkbox" name="settings[aiassistant.mailbox_features_on][{{ $mailbox->id }}][{{ $feature }}]" value="1" @if (App\Ai\Settings::enabled($feature, $mailbox)) checked @endif>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>

        <h3 class="subheader">{{ __('Customer Context') }}</h3>

        <div class="form-group">
            <div class="col-sm-8 col-sm-offset-2">
                <p class="text-help">{{ __('Optional, per mailbox: a URL that is sent the customer\'s email addresses when a reply is drafted, and returns JSON about the customer. Requests are signed with the secret key.') }}</p>
            </div>
        </div>

        @foreach ($ai_mailboxes as $mailbox)
            @php
                $ai_context = App\Ai\CustomerContext::settings($mailbox);
            @endphp
            <div class="ai-customer-context" data-mailbox-id="{{ $mailbox->id }}">
                <div class="form-group">
                    <div class="col-sm-8 col-sm-offset-2">
                        <a href="#ai_context_{{ $mailbox->id }}" data-toggle="collapse"><strong>{{ $mailbox->name }}</strong></a>
                        @if ($ai_context['url'])
                            <span class="text-help">· {{ $ai_context['url'] }}</span>
                        @endif
                    </div>
                </div>
                <div class="collapse @if ($errors->has('settings.aiassistant.customer_context_url.'.$mailbox->id) || $errors->has('settings.aiassistant.customer_context_guidance.'.$mailbox->id)) in @endif" id="ai_context_{{ $mailbox->id }}">
                    <div class="form-group{{ $errors->has('settings.aiassistant.customer_context_url.'.$mailbox->id) ? ' has-error' : '' }}">
                        <label for="ai_context_url_{{ $mailbox->id }}" class="col-sm-2 control-label">{{ __('URL') }}</label>
                        <div class="col-sm-6">
                            <input id="ai_context_url_{{ $mailbox->id }}" type="url" class="form-control input-sized-lg ai-context-url" name="settings[aiassistant.customer_context_url][{{ $mailbox->id }}]" value="{{ old('settings.aiassistant.customer_context_url.'.$mailbox->id, $ai_context['url']) }}" maxlength="2048" placeholder="https://example.com/customer-context">
                            @include('partials/field_error', ['field' => 'settings.aiassistant.customer_context_url.'.$mailbox->id])
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ai_context_secret_{{ $mailbox->id }}" class="col-sm-2 control-label">{{ __('Secret Key') }}</label>
                        <div class="col-sm-6">
                            <input id="ai_context_secret_{{ $mailbox->id }}" type="password" class="form-control input-sized-lg ai-context-secret" name="settings[aiassistant.customer_context_secret_key][{{ $mailbox->id }}]" value="{{ \Helper::safePassword($ai_context['secret_key']) }}" maxlength="255" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ai_context_header_{{ $mailbox->id }}" class="col-sm-2 control-label">{{ __('Signature Header') }}</label>
                        <div class="col-sm-6">
                            <select id="ai_context_header_{{ $mailbox->id }}" class="form-control input-sized ai-context-header" name="settings[aiassistant.customer_context_signature_header][{{ $mailbox->id }}]">
                                @foreach (App\Ai\CustomerContext::HEADERS as $header)
                                    <option value="{{ $header }}" @if ($ai_context['signature_header'] == $header) selected @endif>{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-group{{ $errors->has('settings.aiassistant.customer_context_guidance.'.$mailbox->id) ? ' has-error' : '' }}">
                        <label for="ai_context_guidance_{{ $mailbox->id }}" class="col-sm-2 control-label">{{ __('Reply Guidance') }}</label>
                        <div class="col-sm-6">
                            <textarea id="ai_context_guidance_{{ $mailbox->id }}" class="form-control input-sized-lg" name="settings[aiassistant.customer_context_guidance][{{ $mailbox->id }}]" rows="5" maxlength="6000">{{ old('settings.aiassistant.customer_context_guidance.'.$mailbox->id, $ai_context['guidance']) }}</textarea>
                            <div class="form-help">{{ __('Optional background for drafting replies: who you are, what customers buy, terminology, what fields in the customer context mean, and the reply style.') }}</div>
                            @include('partials/field_error', ['field' => 'settings.aiassistant.customer_context_guidance.'.$mailbox->id])
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ai_context_test_{{ $mailbox->id }}" class="col-sm-2 control-label">{{ __('Test') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group input-sized-lg">
                                <input id="ai_context_test_{{ $mailbox->id }}" type="email" class="form-control ai-context-test-email" placeholder="{{ __('Customer email address') }}">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default ai-context-test" data-loading-text="{{ __('Test') }}…">{{ __('Test') }}</button>
                                </span>
                            </div>
                            <pre class="hidden margin-top-10 ai-context-test-result"></pre>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    <div class="form-group margin-top-0 margin-bottom-0">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>

@section('javascript')
    @parent
    aiSettingsInit();
@endsection

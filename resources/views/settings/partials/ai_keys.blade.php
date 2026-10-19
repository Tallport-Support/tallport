{{-- One set of AI keys (App\Ai\Settings::OWN or HOST, settings/ai): $ai_set, its providers ($ai_set_providers),
 $ai_id (the fields' id prefix). --}}
{{-- The providers set up (App\Ai\Settings::providers()); a new one added with Add Provider. --}}
<x-fruit::form-section :title="__('Providers')" :level="3" :footer="__('API keys: leave the masked value unchanged to keep the current key. Some local providers do not require a key.')">
    @foreach ($ai_set_providers as $provider_id => $ai_provider)
        <x-fruit::disclosure class="ai-provider-block" :title="App\Ai\Providers::label($ai_provider)">
            @include('settings/partials/ai_provider', ['field' => 'settings['.$ai_set.'providers]['.$provider_id.']', 'ai_provider' => $ai_provider])
            <x-fruit::checkbox name="settings[{{ $ai_set }}providers][{{ $provider_id }}][remove]" value="1" :description="__('Features using it switch to the first provider.')">{{ __('Remove This Provider') }}</x-fruit::checkbox>
        </x-fruit::disclosure>
    @endforeach
    <div x-data="{ adding: false }">
        <x-fruit::button size="small" x-show="!adding" x-on:click="adding = true; $nextTick(() => $root.querySelector('select')?.focus())">{{ __('Add Provider') }}</x-fruit::button>
        <template x-if="adding">
            <div class="ai-provider-new">
                @include('settings/partials/ai_provider', ['field' => 'settings['.$ai_set.'providers][new]', 'ai_provider' => ['provider' => 'openai', 'api_key' => '', 'base_url' => '']])
            </div>
        </template>
    </div>
</x-fruit::form-section>

{{-- Each feature's model: the primary, and a backup tried when the primary fails. --}}
<x-fruit::form-section :title="__('Models')" :level="3" :footer="__('When the primary model fails (unreachable, out of credit, a wrong key or model), the backup is used.')">
    <div class="f-table__scroll" tabindex="0" role="region" aria-label="{{ __('Models') }}">
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
                    @php $feature_models = App\Ai\Settings::featureModels($feature, $ai_set); @endphp
                    <tr>
                        <th scope="row">{{ $feature_name }}</th>
                        @foreach (['primary', 'backup'] as $slot)
                            <td data-label="{{ $slot == 'primary' ? __('Primary Model') : __('Backup Model') }}">
                                <div class="ai-model-choice">
                                    <x-fruit::select name="settings[{{ $ai_set }}models][{{ $feature }}][{{ $slot }}][provider]" :aria-label="$feature_name.': '.($slot == 'primary' ? __('Primary Model') : __('Backup Model')).' · '.__('Provider')">
                                        @if ($slot == 'backup')
                                            <option value="">{{ __('None') }}</option>
                                        @endif
                                        @foreach ($ai_set_providers as $provider_id => $ai_provider)
                                            <option value="{{ $provider_id }}" @selected(($feature_models[$slot][0] ?? '') === $provider_id)>{{ App\Ai\Providers::label($ai_provider) }}</option>
                                        @endforeach
                                    </x-fruit::select>
                                    <x-fruit::input name="settings[{{ $ai_set }}models][{{ $feature }}][{{ $slot }}][model]" :value="$feature_models[$slot][1] ?? ''" maxlength="255" :aria-label="$feature_name.': '.($slot == 'primary' ? __('Primary Model') : __('Backup Model'))" :placeholder="$slot == 'primary' ? App\Ai\Settings::DEFAULT_MODEL : ''" />
                                </div>
                                {{-- How its last calls went (the AI log). --}}
                                @if (!empty($feature_models[$slot]) && ($model_status = App\Ai\Usage::modelStatus($feature, ...$feature_models[$slot])))
                                    <a wire:navigate href="{{ route('logs.ai', array_filter(['model' => $feature_models[$slot][1], 'outcome' => $model_status['failing'] ? 'errors' : null])) }}" @class(['ai-model-status', 'ai-model-status--failing' => $model_status['failing']])>{{ $model_status['text'] }}</a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </x-fruit::table>
    </div>
</x-fruit::form-section>

<x-fruit::form-section :title="__('Documentation')" :level="3">
    @if (!App\Ai\Settings::embeddingsAvailable($ai_set))
        <x-fruit::alert tone="warning">{{ __('The selected embedding provider does not support embeddings, so drafts are made without documentation. Summaries and translations are not affected.') }}</x-fruit::alert>
    @endif

    <x-fruit::field :label="__('Embedding Provider')" layout="row">
        <x-fruit::select name="settings[{{ $ai_set }}documentation.embedding_provider]" class="ai-provider" id="{{ $ai_id }}embedding_provider" data-base-url="#{{ $ai_id }}embedding_base_url" x-data x-on:change="document.querySelector($el.dataset.baseUrl).placeholder = $el.selectedOptions[0].dataset.baseUrl">
            <option value="same" data-base-url="" @selected($settings[$ai_set.'documentation.embedding_provider'] == 'same')>{{ __('Same as AI Provider') }}</option>
            @foreach (App\Ai\Providers::PRESETS as $provider => $preset)
                @if ($preset['embedding_model'])
                    <option value="{{ $provider }}" data-base-url="{{ $preset['base_url'] }}" @selected($settings[$ai_set.'documentation.embedding_provider'] == $provider)>{{ $preset['name'] }}</option>
                @endif
            @endforeach
        </x-fruit::select>
    </x-fruit::field>

    <x-fruit::field :label="__('Embedding API Key')" :description="__('Leave blank when reusing the AI provider key. Leave the masked value unchanged to keep the current key.')" layout="row">
        <x-fruit::input type="password" id="{{ $ai_id }}embedding_api_key" name="settings[{{ $ai_set }}documentation.embedding_api_key]" :value="\Helper::safePassword($settings[$ai_set.'documentation.embedding_api_key'])" autocomplete="new-password" />
    </x-fruit::field>

    <x-fruit::field :label="__('Embedding Base URL')" :description="__('Optional. Leave blank to use the selected embedding provider default.')" layout="row">
        <x-fruit::input type="url" id="{{ $ai_id }}embedding_base_url" name="settings[{{ $ai_set }}documentation.embedding_base_url]" :value="old('settings.'.$ai_set.'documentation.embedding_base_url', $settings[$ai_set.'documentation.embedding_base_url'])" :placeholder="App\Ai\Settings::embeddingProviderIsSame($ai_set) ? '' : App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider($ai_set)]['base_url']" />
    </x-fruit::field>

    <x-fruit::field :label="__('Embedding Model')" layout="row">
        <x-fruit::input id="{{ $ai_id }}embedding_model" name="settings[{{ $ai_set }}documentation.embedding_model]" :value="$settings[$ai_set.'documentation.embedding_model']" maxlength="255" :placeholder="App\Ai\Providers::PRESETS[App\Ai\Settings::embeddingProvider($ai_set)]['embedding_model']" />
    </x-fruit::field>

</x-fruit::form-section>

{{-- The host's limit (the installation's own is under Conversations). --}}
@if ($ai_set == App\Ai\Settings::HOST)
    <x-fruit::form-section>
        <x-fruit::field :label="__('Daily Tokens Per Mailbox')" :description="__('Tokens each mailbox may use per day, for all AI features. When they are used up, the AI is unavailable in that mailbox until tomorrow. 0: no limit.')" layout="row">
            <x-fruit::number id="{{ $ai_id }}daily_tokens" name="settings[{{ $ai_set }}daily_tokens]" :value="old('settings.'.$ai_set.'daily_tokens', $settings[$ai_set.'daily_tokens'])" min="0" max="1000000000" />
        </x-fruit::field>
    </x-fruit::form-section>
@endif

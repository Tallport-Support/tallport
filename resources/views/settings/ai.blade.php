{{-- AI settings: whether to use one's own keys instead of the host's (when the host provides AI,
     HOSTING.md; its keys are the operator's, settings/ai_host), the installation's own keys
     (App\Ai\Settings::OWN), then how documentation and conversations use the AI. --}}
@php $ai_host = App\Ai\Settings::isConfigured(App\Ai\Settings::HOST); @endphp
<form class="settings-form" method="POST" action="" id="ai_settings_form" x-on:change="Tallport.autoSave($el)" x-data="{ ownKeys: @js(!$ai_host || $settings['aiassistant.own_keys']) }">
    {{ csrf_field() }}

    @if ($ai_host)
        <x-fruit::form-section>
            <x-fruit::field :label="__('Use Your Own API Keys')" :description="__('Your host provides the AI. Use your own API keys instead, for privacy or to pay the provider yourself.')" layout="row">
                <x-fruit::switch id="ai_own_keys" name="settings[aiassistant.own_keys]" value="1" :checked="(bool) $settings['aiassistant.own_keys']" x-model="ownKeys" />
            </x-fruit::field>
        </x-fruit::form-section>
    @endif

    <div class="settings-form" x-show="ownKeys">
        @include('settings/partials/ai_keys', ['ai_set' => App\Ai\Settings::OWN, 'ai_set_providers' => $ai_providers, 'ai_id' => 'ai_'])
    </div>

    <x-fruit::form-section>
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

    <x-fruit::form-section :title="__('Conversations')" :level="3">
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

        <x-fruit::field :label="__('Daily Tokens Per Mailbox')" x-show="ownKeys" :description="__('Tokens each mailbox may use per day, for all AI features. When they are used up, the AI is unavailable in that mailbox until tomorrow. 0: no limit.')" layout="row">
            <x-fruit::number id="ai_daily_tokens" name="settings[aiassistant.daily_tokens]" :value="old('settings.aiassistant.daily_tokens', $settings['aiassistant.daily_tokens'])" min="0" max="1000000000" />
        </x-fruit::field>

        <x-fruit::field :label="__('Translations Per Customer Per Hour')" :description="__('Messages of one customer translated per hour, against floods; more are shown untranslated. 0: no limit.')" layout="row">
            <x-fruit::number id="ai_translations_per_customer_hour" name="settings[aiassistant.translations_per_customer_hour]" :value="old('settings.aiassistant.translations_per_customer_hour', $settings['aiassistant.translations_per_customer_hour'])" min="0" max="10000" />
        </x-fruit::field>
    </x-fruit::form-section>

</form>

{{-- One AI provider's fields (settings/ai): $field (the inputs' name), $ai_provider. --}}
<div class="settings-form" x-data="{ base: @js(App\Ai\Providers::PRESETS[$ai_provider['provider']]['base_url']), preset: @js($ai_provider['provider']) }">
    <x-fruit::field :label="__('Provider')">
        <x-fruit::select name="{{ $field }}[provider]" x-on:change="base = $el.selectedOptions[0].dataset.baseUrl; preset = $el.value">
            @foreach (App\Ai\Providers::PRESETS as $preset_key => $preset)
                <option value="{{ $preset_key }}" data-base-url="{{ $preset['base_url'] }}" @selected($ai_provider['provider'] == $preset_key)>{{ $preset['name'] }}</option>
            @endforeach
        </x-fruit::select>
    </x-fruit::field>
    <x-fruit::field :label="__('API Key')">
        <x-fruit::input type="password" name="{{ $field }}[api_key]" :value="\Helper::safePassword((string) \Helper::decrypt($ai_provider['api_key']))" autocomplete="new-password" />
    </x-fruit::field>
    <x-fruit::field :label="__('Base URL')" :description="__('Optional. Leave blank to use the selected provider default.')">
        <x-fruit::input type="url" name="{{ $field }}[base_url]" :value="$ai_provider['base_url']" x-bind:placeholder="base" />
    </x-fruit::field>
    {{-- Fast mode (Providers::fastTierOptions()), for the providers that have it: shown (and sent) for the
         provider chosen, with the wording for all its models or only OpenAI's. --}}
    @foreach ([true, false] as $ai_fast_all)
        @php $ai_fast_presets = array_keys(array_filter(App\Ai\Providers::FAST_TIER, fn ($all) => $all === $ai_fast_all)); @endphp
        <div x-show="@js($ai_fast_presets).includes(preset)">
            <x-fruit::field :label="__('Fast Mode')" :description="App\Ai\Providers::fastTierDescription($ai_fast_presets[0])" layout="row">
                <x-fruit::switch name="{{ $field }}[fast_mode]" value="1" :checked="!empty($ai_provider['fast_mode']) && in_array($ai_provider['provider'], $ai_fast_presets)" x-bind:disabled="!{{ \Illuminate\Support\Js::from($ai_fast_presets) }}.includes(preset)" />
            </x-fruit::field>
        </div>
    @endforeach
</div>

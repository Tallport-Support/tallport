{{-- One AI provider's fields (settings/ai): $field (the inputs' name), $ai_provider. --}}
<div class="settings-form" x-data="{ base: @js(App\Ai\Providers::PRESETS[$ai_provider['provider']]['base_url']) }">
    <x-fruit::field :label="__('Provider')">
        <x-fruit::select name="{{ $field }}[provider]" x-on:change="base = $el.selectedOptions[0].dataset.baseUrl">
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
</div>

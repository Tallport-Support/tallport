{{-- The AI keys the host provides (App\Ai\Settings::HOST, HOSTING.md): the operator's. Used until the
     installation chooses its own (settings/ai). --}}
<form class="settings-form" method="POST" action="" id="ai_host_settings_form" x-on:change="Tallport.autoSave($el)">
    {{ csrf_field() }}

    @include('settings/partials/ai_keys', ['ai_set' => App\Ai\Settings::HOST, 'ai_set_providers' => $ai_providers, 'ai_id' => 'ai_host_'])
</form>

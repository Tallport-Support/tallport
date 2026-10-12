{{-- API & Webhooks: the API (saved as it changes), users' keys, and webhooks as rows, each opening
     its own level (settings/api_webhook). --}}
<div class="settings-form">
    <form class="settings-form" method="POST" action="{{ route('settings.save', ['section' => 'api']) }}" x-on:change="Tallport.autoSave($el)">
        {{ csrf_field() }}

        <x-fruit::form-section :title="__('API')" :level="3">
            <x-fruit::field :label="__('API Key')" :description="__('The global key may do everything, in every mailbox. Users can make keys that act as themselves in their profile (API Keys).').' '.__('Send the key in the X-Tallport-API-Key header.')" layout="row">
                <x-fruit::input id="api_key" :value="$api_key" readonly />
            </x-fruit::field>

            <x-fruit::field :label="__('Allowed CORS Hosts')" :description="trim(__('Websites that may call the API from a browser, comma separated; * for any. Leave empty for none.').' '.App\Misc\DatabaseSettings::lockedNote('api.cors_hosts'))" layout="row">
                <x-fruit::input id="api_cors_hosts" name="settings[api.cors_hosts]" :value="old('settings.api.cors_hosts', $settings['api.cors_hosts'])" placeholder="https://example.org" :disabled="App\Misc\DatabaseSettings::lockedByEnv('api.cors_hosts')" />
            </x-fruit::field>

            <div class="f-form-row">
                <x-fruit::button type="submit" form="api_regenerate_form">{{ __('Generate a New API Key') }}</x-fruit::button>
            </div>
        </x-fruit::form-section>
    </form>

    <x-fruit::form-section :title="__('API Keys')" :level="3">
        @if (count($api_keys))
            <div>
                <x-fruit::table>
                    <thead>
                        <tr>
                            <th>{{ __('User') }}</th>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Key') }}</th>
                            <th>{{ __('Access') }}</th>
                            <th>{{ __('Mailboxes') }}</th>
                            <th>{{ __('Last used') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($api_keys as $key)
                            <tr>
                                <td>{{ $key->user ? $key->user->getFullName() : '' }}</td>
                                <td>{{ $key->name }}</td>
                                <td><code>…{{ $key->token_preview }}</code></td>
                                <td>@if ($key->canWrite()){{ __('Read and write') }}@else{{ __('Read only') }}@endif</td>
                                <td>@if ($key->mailboxes){{ $mailboxes->whereIn('id', $key->mailboxes)->pluck('name')->implode(' | ') }}@else{{ __('All') }}@endif</td>
                                <td>@if ($key->last_used_at){{ App\User::dateFormat($key->last_used_at) }}@else{{ __('Never') }}@endif</td>
                                <td>
                                    <form method="POST" action="{{ route('settings.api.action') }}" x-data x-on:submit.prevent="Tallport.confirm({message: @js(__('Revoke this API key?')), confirm: @js(__('Revoke')), tone: 'danger'}).then(ok => ok && $el.submit())">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="action" value="revoke_key">
                                        <input type="hidden" name="key_id" value="{{ $key->id }}">
                                        <x-fruit::button type="submit" variant="danger" size="small">{{ __('Revoke') }}</x-fruit::button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            </div>
        @else
            <p class="f-muted">{{ __('No API keys yet.') }}</p>
        @endif
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Webhooks')" :level="3" :footer="__('Events are sent as a POST with the conversation or customer as JSON (as the API returns it). The X-Tallport-Event header names the event; X-Tallport-Signature is the base64 HMAC-SHA1 of the body with the secret key below. Failed deliveries are tried again for about an hour and a half.')">
        <x-fruit::field :label="__('Secret Key')" layout="row">
            <x-fruit::input id="webhook_secret" :value="$webhook_secret" readonly />
        </x-fruit::field>
        @foreach ($webhooks as $webhook_row)
            <a class="f-form-row f-form-row--link" href="{{ route('settings.api.webhook', ['id' => $webhook_row->id]) }}" wire:navigate @if (isset($webhook) && $webhook->id == $webhook_row->id) aria-current="page" @endif><span class="webhook-row__url">{{ $webhook_row->url }}</span><span class="f-form-row__value">{{ trans_choice('1 event|:count events', count((array) $webhook_row->events)) }}</span></a>
        @endforeach
        <div class="f-form-row">
            <a class="f-button" href="{{ route('settings.api.webhook', ['id' => 'new']) }}" wire:navigate @if (isset($webhook) && !$webhook->exists) aria-current="page" @endif>{{ __('Add Webhook') }}</a>
        </div>
    </x-fruit::form-section>

    {{-- Forms that buttons above submit with their form attribute. --}}
    <div hidden>
        <form id="api_regenerate_form" method="POST" action="{{ route('settings.api.action') }}" x-data x-on:submit.prevent="Tallport.confirm({message: @js(__('Integrations using the current API key will stop working. Continue?')), confirm: @js(__('Generate a New API Key')), tone: 'danger'}).then(ok => ok && $el.submit())">
            {{ csrf_field() }}
            <input type="hidden" name="action" value="regenerate_key">
        </form>
    </div>
</div>

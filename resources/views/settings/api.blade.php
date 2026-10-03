<form class="settings-form" method="POST" action="">
    {{ csrf_field() }}

    <h2 class="settings-form__heading">{{ __('API') }}</h2>

    <x-fruit::field :label="__('API Key')" :description="__('The global key may do everything, in every mailbox. Users can make keys that act as themselves in their profile (API Keys).').' '.__('Send the key in the X-FreeScout-API-Key header.')">
        <x-fruit::input id="api_key" :value="$api_key" readonly />
    </x-fruit::field>

    <x-fruit::field :label="__('Allowed CORS Hosts')" :description="__('Websites that may call the API from a browser, comma separated; * for any. Leave empty for none.')">
        <x-fruit::input id="api_cors_hosts" name="settings[api.cors_hosts]" :value="old('settings.api.cors_hosts', $settings['api.cors_hosts'])" placeholder="https://example.org" />
    </x-fruit::field>

    <div class="settings-form__actions f-row">
        <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
        <x-fruit::button type="submit" form="api_regenerate_form">{{ __('Generate a new API key') }}</x-fruit::button>
    </div>
</form>

<form id="api_regenerate_form" method="POST" action="{{ route('settings.api.action') }}" onsubmit="return confirm({{ json_encode(__('Integrations using the current API key will stop working. Continue?')) }});">
    {{ csrf_field() }}
    <input type="hidden" name="action" value="regenerate_key">
</form>

<div class="settings-form">
    <h2 class="settings-form__heading">{{ __('API Keys') }}</h2>
    @if (count($api_keys))
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
                            <form method="POST" action="{{ route('settings.api.action') }}" onsubmit="return confirm({{ json_encode(__('Revoke this API key?')) }});">
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
    @else
        <p class="f-muted">{{ __('No API keys yet.') }}</p>
    @endif

    <h2 class="settings-form__heading">{{ __('Webhooks') }}</h2>
    <p class="f-help">
        {{ __('Events are sent as a POST with the conversation or customer as JSON (as the API returns it). The X-FreeScout-Event header names the event; X-FreeScout-Signature is the base64 HMAC-SHA1 of the body with the secret key below. Failed deliveries are tried again for about an hour and a half.') }}
    </p>

    <x-fruit::field :label="__('Secret Key')">
        <x-fruit::input id="webhook_secret" :value="$webhook_secret" readonly />
    </x-fruit::field>

    @foreach ($webhooks->push(new App\Api\Webhook()) as $webhook)
        <x-fruit::card class="f-stack">
            <div class="f-row">
                @if ($webhook->exists)
                    <strong>{{ $webhook->url }}</strong>
                    @if (!$webhook->last_run_time)
                        <x-fruit::badge>{{ __('Not used yet') }}</x-fruit::badge>
                    @elseif ($webhook->last_run_error)
                        <x-fruit::badge tone="danger">{{ $webhook->last_run_error }}</x-fruit::badge> <small class="f-muted">{{ App\User::dateFormat($webhook->last_run_time) }}</small>
                    @else
                        <x-fruit::badge tone="success">OK</x-fruit::badge> <small class="f-muted">{{ App\User::dateFormat($webhook->last_run_time) }}</small>
                    @endif
                @else
                    <strong>{{ __('Add Webhook') }}</strong>
                @endif
            </div>

            <form class="settings-form" method="POST" action="{{ route('settings.api.action') }}">
                {{ csrf_field() }}
                <input type="hidden" name="action" value="save_webhook">
                <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">

                <x-fruit::field :label="__('URL')">
                    <x-fruit::input type="url" id="webhook_url_{{ $webhook->id ?: 'new' }}" name="url" :value="$webhook->url" maxlength="255" required placeholder="https://example.org/webhook" />
                </x-fruit::field>
                <x-fruit::fieldset>
                    <legend>{{ __('Events') }}</legend>
                    @foreach ($webhook_events as $event)
                        <x-fruit::checkbox name="events[]" :value="$event" :checked="in_array($event, (array) $webhook->events)"><code>{{ $event }}</code></x-fruit::checkbox>
                    @endforeach
                </x-fruit::fieldset>
                <x-fruit::fieldset>
                    <legend>{{ __('Mailboxes') }}</legend>
                    @foreach ($mailboxes as $mailbox)
                        <x-fruit::checkbox name="mailboxes[]" :value="$mailbox->id" :checked="in_array($mailbox->id, array_map('intval', (array) $webhook->mailboxes))">{{ $mailbox->name }}</x-fruit::checkbox>
                    @endforeach
                    <p class="f-help">{{ __('None: conversations of every mailbox. Customer events are always sent.') }}</p>
                </x-fruit::fieldset>
                <div class="f-row">
                    <x-fruit::button type="submit" variant="primary">@if ($webhook->exists){{ __('Save') }}@else{{ __('Add Webhook') }}@endif</x-fruit::button>
                    @if ($webhook->exists)
                        <x-fruit::button type="submit" variant="danger" form="webhook_delete_{{ $webhook->id }}">{{ __('Delete') }}</x-fruit::button>
                    @endif
                </div>
            </form>

            @if ($webhook->exists)
                <form id="webhook_delete_{{ $webhook->id }}" method="POST" action="{{ route('settings.api.action') }}" onsubmit="return confirm({{ json_encode(__('Delete this webhook?')) }});">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="delete_webhook">
                    <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">
                </form>
                @php
                    $logs = $webhook->logs()->orderBy('id', 'desc')->limit(20)->get();
                @endphp
                @if (count($logs))
                    <x-fruit::disclosure :title="__('Failed deliveries').' ('.count($logs).')'">
                        <x-fruit::table>
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Event') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Error') }}</th>
                                    <th>{{ __('Attempts') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($logs as $log)
                                    <tr>
                                        <td>{{ App\User::dateFormat($log->updated_at) }}</td>
                                        <td><code>{{ $log->event }}</code></td>
                                        <td><x-fruit::badge :tone="$log->isSuccess() ? 'success' : 'danger'">{{ $log->status_code ?: '—' }}</x-fruit::badge></td>
                                        <td>{{ $log->error }}</td>
                                        <td>@if ($log->finished){{ $log->attempts }}@else{{ $log->attempts }} / {{ App\Api\Webhook::MAX_ATTEMPTS }}@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-fruit::table>
                    </x-fruit::disclosure>
                @endif
            @endif
        </x-fruit::card>
    @endforeach
</div>

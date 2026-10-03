<form class="form-horizontal margin-top" method="POST" action="">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('API') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API Key') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" value="{{ $api_key }}" readonly>
            <div class="form-help">
                {{ __('The global key may do everything, in every mailbox. Users can make keys that act as themselves in their profile (API Keys).') }}
                {{ __('Send the key in the X-FreeScout-API-Key header.') }}
            </div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.api.cors_hosts') ? ' has-error' : '' }}">
        <label for="api_cors_hosts" class="col-sm-2 control-label">{{ __('Allowed CORS Hosts') }}</label>
        <div class="col-sm-6">
            <input id="api_cors_hosts" type="text" class="form-control input-sized" name="settings[api.cors_hosts]" value="{{ old('settings.api.cors_hosts', $settings['api.cors_hosts']) }}" placeholder="https://example.org">
            <div class="form-help">{{ __('Websites that may call the API from a browser, comma separated; * for any. Leave empty for none.') }}</div>
            @include('partials/field_error', ['field' => 'settings.api.cors_hosts'])
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

<form class="form-horizontal" method="POST" action="{{ route('settings.api.action') }}" onsubmit="return confirm({{ json_encode(__('Integrations using the current API key will stop working. Continue?')) }});">
    {{ csrf_field() }}
    <input type="hidden" name="action" value="regenerate_key">
    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-default">{{ __('Generate a new API key') }}</button>
        </div>
    </div>
</form>

<h3 class="subheader">{{ __('API Keys') }}</h3>
@if (count($api_keys))
    <table class="table table-borderless table-condensed">
        <tr>
            <th>{{ __('User') }}</th>
            <th>{{ __('Name') }}</th>
            <th>{{ __('Key') }}</th>
            <th>{{ __('Access') }}</th>
            <th>{{ __('Mailboxes') }}</th>
            <th>{{ __('Last used') }}</th>
            <th></th>
        </tr>
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
                        <button type="submit" class="btn btn-link btn-xs text-danger">{{ __('Revoke') }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
@else
    <p class="text-help">{{ __('No API keys yet.') }}</p>
@endif

<h3 class="subheader">{{ __('Webhooks') }}</h3>
<p class="text-help">
    {{ __('Events are sent as a POST with the conversation or customer as JSON (as the API returns it). The X-FreeScout-Event header names the event; X-FreeScout-Signature is the base64 HMAC-SHA1 of the body with the secret key below. Failed deliveries are tried again for about an hour and a half.') }}
</p>
<div class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Secret Key') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" value="{{ $webhook_secret }}" readonly>
        </div>
    </div>
</div>

@foreach ($webhooks->push(new App\Api\Webhook()) as $webhook)
    <div class="panel panel-default">
        <div class="panel-heading">
            @if ($webhook->exists)
                <strong>{{ $webhook->url }}</strong>
                <span class="pull-right">
                    @if (!$webhook->last_run_time)
                        <span class="text-help">{{ __('Not used yet') }}</span>
                    @elseif ($webhook->last_run_error)
                        <span class="text-danger">{{ $webhook->last_run_error }}</span> <small class="text-help">({{ App\User::dateFormat($webhook->last_run_time) }})</small>
                    @else
                        <span class="text-success">OK</span> <small class="text-help">({{ App\User::dateFormat($webhook->last_run_time) }})</small>
                    @endif
                </span>
            @else
                <strong>{{ __('Add Webhook') }}</strong>
            @endif
        </div>
        <div class="panel-body">
            <form class="form-horizontal" method="POST" action="{{ route('settings.api.action') }}">
                {{ csrf_field() }}
                <input type="hidden" name="action" value="save_webhook">
                <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('URL') }}</label>
                    <div class="col-sm-8">
                        <input type="url" class="form-control" name="url" value="{{ $webhook->url }}" maxlength="255" required placeholder="https://example.org/webhook">
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Events') }}</label>
                    <div class="col-sm-8">
                        @foreach ($webhook_events as $event)
                            <label class="checkbox-inline"><input type="checkbox" name="events[]" value="{{ $event }}" @if (in_array($event, (array) $webhook->events)) checked @endif> <code>{{ $event }}</code></label>
                        @endforeach
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Mailboxes') }}</label>
                    <div class="col-sm-8">
                        @foreach ($mailboxes as $mailbox)
                            <label class="checkbox-inline"><input type="checkbox" name="mailboxes[]" value="{{ $mailbox->id }}" @if (in_array($mailbox->id, array_map('intval', (array) $webhook->mailboxes))) checked @endif> {{ $mailbox->name }}</label>
                        @endforeach
                        <div class="form-help">{{ __('None: conversations of every mailbox. Customer events are always sent.') }}</div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-8 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">@if ($webhook->exists){{ __('Save') }}@else{{ __('Add Webhook') }}@endif</button>
                    </div>
                </div>
            </form>

            @if ($webhook->exists)
                <form method="POST" action="{{ route('settings.api.action') }}" class="pull-right" onsubmit="return confirm({{ json_encode(__('Delete this webhook?')) }});">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="delete_webhook">
                    <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">
                    <button type="submit" class="btn btn-link text-danger">{{ __('Delete') }}</button>
                </form>
                @php
                    $logs = $webhook->logs()->orderBy('id', 'desc')->limit(20)->get();
                @endphp
                @if (count($logs))
                    <a href="#webhook-logs-{{ $webhook->id }}" data-toggle="collapse" class="small">{{ __('Failed deliveries') }} ({{ count($logs) }})</a>
                    <div id="webhook-logs-{{ $webhook->id }}" class="collapse">
                        <table class="table table-condensed small margin-top">
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Event') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Error') }}</th>
                                <th>{{ __('Attempts') }}</th>
                            </tr>
                            @foreach ($logs as $log)
                                <tr>
                                    <td>{{ App\User::dateFormat($log->updated_at) }}</td>
                                    <td><code>{{ $log->event }}</code></td>
                                    <td class="@if ($log->isSuccess()) text-success @else text-danger @endif">{{ $log->status_code ?: '—' }}</td>
                                    <td>{{ $log->error }}</td>
                                    <td>@if ($log->finished){{ $log->attempts }}@else{{ $log->attempts }} / {{ App\Api\Webhook::MAX_ATTEMPTS }}@endif</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endforeach

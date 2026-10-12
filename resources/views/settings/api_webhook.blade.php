{{-- A webhook as a level beside API & Webhooks (settings/view): saved as it changes; a new one is
     added with its button, then opens as its own level. --}}
<x-fruit::drilldown-column :title="$webhook->exists ? __('Webhook') : __('Add Webhook')" id="settings-webhook">
    <x-slot:back><x-fruit::back-link :href="route('settings', ['section' => 'api'])" wire:navigate>{{ $section_name }}</x-fruit::back-link></x-slot:back>
    <x-fruit::page width="narrow">
        <form class="settings-form" method="POST" action="{{ route('settings.api.action') }}" @if ($webhook->exists) x-on:change="Tallport.autoSave($el)" @endif>
            {{ csrf_field() }}
            <input type="hidden" name="action" value="save_webhook">
            <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">

            <x-fruit::form-section>
                @if ($webhook->exists)
                    <div class="f-form-row">
                        <span>{{ __('Status') }}</span>
                        <div class="f-row">
                            @if (!$webhook->last_run_time)
                                <x-fruit::badge>{{ __('Not used yet') }}</x-fruit::badge>
                            @elseif ($webhook->last_run_error)
                                <x-fruit::badge tone="danger">{{ $webhook->last_run_error }}</x-fruit::badge> <small class="f-muted">{{ App\User::dateFormat($webhook->last_run_time) }}</small>
                            @else
                                <x-fruit::badge tone="success">OK</x-fruit::badge> <small class="f-muted">{{ App\User::dateFormat($webhook->last_run_time) }}</small>
                            @endif
                        </div>
                    </div>
                @endif
                <x-fruit::field :label="__('URL')" layout="row">
                    <x-fruit::input type="url" id="webhook_url" name="url" :value="old('url', $webhook->url)" maxlength="255" required placeholder="https://example.org/webhook" />
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Events')" :level="3">
                <x-fruit::fieldset>
                    <legend class="f-sr-only">{{ __('Events') }}</legend>
                    @foreach ($webhook_events as $event)
                        <x-fruit::checkbox name="events[]" :value="$event" :checked="in_array($event, (array) old('events', $webhook->events))"><code>{{ $event }}</code></x-fruit::checkbox>
                    @endforeach
                </x-fruit::fieldset>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Mailboxes')" :level="3" :footer="__('None: conversations of every mailbox. Customer events are always sent.')">
                <x-fruit::fieldset>
                    <legend class="f-sr-only">{{ __('Mailboxes') }}</legend>
                    @foreach ($mailboxes as $mailbox)
                        <x-fruit::checkbox name="mailboxes[]" :value="$mailbox->id" :checked="in_array($mailbox->id, array_map('intval', (array) old('mailboxes', $webhook->mailboxes)))">{{ $mailbox->name }}</x-fruit::checkbox>
                    @endforeach
                </x-fruit::fieldset>
            </x-fruit::form-section>

            @if ($webhook->exists)
                @php $logs = $webhook->logs()->orderBy('id', 'desc')->limit(20)->get(); @endphp
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
            @else
                <div class="f-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Add Webhook') }}</x-fruit::button>
                </div>
            @endif
        </form>

        @if ($webhook->exists)
            <x-fruit::form-section :title="__('Danger Zone')" :level="3">
                <form class="f-form-row" method="POST" action="{{ route('settings.api.action') }}" x-data x-on:submit.prevent="Tallport.confirm({message: {{ \Illuminate\Support\Js::from(__('Delete this webhook?')) }}, confirm: {{ \Illuminate\Support\Js::from(__('Delete')) }}, tone: 'danger'}).then(ok => ok && $el.submit())">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="delete_webhook">
                    <input type="hidden" name="webhook_id" value="{{ $webhook->id }}">
                    <x-fruit::button type="submit" variant="danger">{{ __('Delete Webhook') }}</x-fruit::button>
                </form>
            </x-fruit::form-section>
        @endif
    </x-fruit::page>
</x-fruit::drilldown-column>

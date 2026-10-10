@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Nostr').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form settings-form--wide">
            <p class="f-help">
                {{ __('Customers can write to this mailbox with any Nostr client that supports private direct messages (NIP-17). Their messages become chat conversations and your replies are delivered back encrypted.') }}
            </p>

            @if ($cfg->pubkey)
                <x-fruit::card class="f-stack">
                    <h2 class="f-title-3">{{ __('Identity of This Mailbox') }}</h2>
                    <dl class="nostr-facts">
                        <dt>{{ __('Public key') }}</dt>
                        <dd><code>{{ $cfg->getNpub() }}</code> <x-fruit::copy-button size="small" :value="$cfg->getNpub()">{{ __('Copy') }}</x-fruit::copy-button></dd>
                        <dt>{{ __('Hex') }}</dt>
                        <dd><small class="f-muted">{{ $cfg->pubkey }}</small> <x-fruit::copy-button size="small" :value="$cfg->pubkey">{{ __('Copy') }}</x-fruit::copy-button></dd>
                        <dt>{{ __('Key since') }}</dt>
                        <dd>{{ $cfg->key_created_at ? App\User::dateFormat($cfg->key_created_at) : '' }}</dd>
                        @if ($cfg->getNip05())
                            <dt>{{ __('Address') }}</dt>
                            <dd>{{ $cfg->getNip05() }} @if ($cfg->nip05ServedHere())<x-fruit::badge tone="success">{{ __('served by this installation') }}</x-fruit::badge>@else<x-fruit::badge tone="warning">{{ __('needs the file below on :domain', ['domain' => $cfg->getNip05Domain()]) }}</x-fruit::badge>@endif</dd>
                        @endif
                        <dt>{{ __('Messages') }}</dt>
                        <dd>{{ __(':in received, :out sent', ['in' => $stats['incoming'], 'out' => $stats['outgoing']]) }}@if ($stats['failed']), <span class="f-error nostr-inline">{{ __(':failed failed', ['failed' => $stats['failed']]) }}</span>@endif</dd>
                        <dt>{{ __('Last message') }}</dt>
                        <dd>{{ $cfg->last_event_at ? App\User::dateFormat($cfg->last_event_at) : __('never') }}</dd>
                        <dt>{{ __('Last announced') }}</dt>
                        <dd>{{ $cfg->last_announced_at ? App\User::dateFormat($cfg->last_announced_at) : __('never') }}</dd>
                    </dl>
                </x-fruit::card>
            @endif

            @php
                $ls = $listener['status'] ?? [];
                $lstate = $listener['state'] ?? 'never';
                $ago = function ($ts) { return $ts ? \Illuminate\Support\Carbon::createFromTimestamp($ts)->diffForHumans() : ''; };
                $labels = [
                    'running'    => ['success', __('Running')],
                    'restarting' => ['accent',  __('Restarting')],
                    'stale'      => ['danger',  __('Not responding')],
                    'stopped'    => ['danger',  __('Stopped')],
                    'never'      => ['warning', __('Not started yet')],
                    'disabled'   => ['neutral', __('Off')],
                ];
            @endphp
            <x-fruit::card class="f-stack">
                <h2 class="f-title-3 f-row">{{ __('Listener') }} <x-fruit::badge :tone="$labels[$lstate][0]">{{ $labels[$lstate][1] }}</x-fruit::badge></h2>

                @if ($lstate == 'disabled')
                    <p class="f-help">{{ __('The listener starts automatically once this channel is enabled and has a key and inbox relays.') }}</p>
                @elseif ($lstate == 'running')
                    <p>{{ __('Process :pid on :host, started :started, restarts :ends.', ['pid' => $ls['pid'] ?? '?', 'host' => $ls['host'] ?? '?', 'started' => $ago($ls['started_at'] ?? null), 'ends' => $ago($ls['ends_at'] ?? null)]) }} <small class="f-muted">{{ __('Last heartbeat :ago.', ['ago' => $ago($ls['heartbeat_at'] ?? null)]) }}</small></p>
                @elseif ($lstate == 'restarting')
                    <p>{{ __('The previous process finished its scheduled run :ago; the cron job starts a new one within a minute.', ['ago' => $ago($ls['stopped_at'] ?? null)]) }}</p>
                @elseif ($lstate == 'stopped')
                    <x-fruit::alert tone="danger">{{ __('The listener stopped :ago (:reason) and has not been started again.', ['ago' => $ago($ls['stopped_at'] ?? null), 'reason' => $ls['stop_reason'] ?? '?']) }}</x-fruit::alert>
                @elseif ($lstate == 'stale')
                    <x-fruit::alert tone="danger">{{ __('No heartbeat since :ago. The process was probably killed or the server rebooted; the cron job should start a new one within a minute.', ['ago' => $ago($ls['heartbeat_at'] ?? null)]) }}</x-fruit::alert>
                @else
                    <x-fruit::alert tone="warning">{{ __('The listener has never reported in. It is started by the cron job (php artisan schedule:run every minute) within a minute of enabling the channel.') }}</x-fruit::alert>
                @endif

                @if ($listener['cron_ok'] === false)
                    <x-fruit::alert tone="danger">{{ __('The cron job last ran :ago. Without it neither emails nor the listener run.', ['ago' => $ago($listener['cron_last_run'])]) }}</x-fruit::alert>
                @elseif ($listener['cron_ok'] === true)
                    <p class="f-help">{{ __('The cron job last ran :ago.', ['ago' => $ago($listener['cron_last_run'])]) }}</p>
                @endif

                @if ($lstate != 'disabled' && count($listener['relays']))
                    <div class="f-table__scroll">
                        <x-fruit::table class="nostr-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Inbox relay') }}</th>
                                    <th>{{ __('Connection') }}</th>
                                    <th>{{ __('Messages') }}</th>
                                    <th>{{ __('Details') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($listener['relays'] as $url => $relay)
                                    @php
                                        $rstate = $lstate == 'running' ? ($relay['state'] ?? 'none') : 'none';
                                        $rlabel = ['connected' => ['success', __('connected')], 'connecting' => ['warning', __('connecting')], 'reconnecting' => ['warning', __('reconnecting')], 'none' => ['neutral', __('not connected')]][$rstate] ?? ['neutral', $rstate];
                                    @endphp
                                    <tr>
                                        <td><code>{{ $url }}</code></td>
                                        <td><x-fruit::badge :tone="$rlabel[0]">{{ $rlabel[1] }}</x-fruit::badge> @if ($rstate == 'connected' && !empty($relay['since']))<small class="f-muted">{{ __('since') }} {{ $ago($relay['since']) }}</small>@endif</td>
                                        <td>{{ (int) ($relay['events'] ?? 0) }}@if (!empty($relay['last_event_at'])) <small class="f-muted">({{ __('last') }} {{ $ago($relay['last_event_at']) }})</small>@endif</td>
                                        <td>
                                            @if ($rstate == 'connected')
                                                {{ !empty($relay['caught_up']) ? __('subscribed') : __('waiting for the relay') }}{{ !empty($relay['authed']) ? ', '.__('authenticated') : '' }}
                                            @elseif ($rstate == 'reconnecting')
                                                {{ __('retry in :s s', ['s' => $relay['retry_in'] ?? '?']) }}@if (!empty($relay['error'])): <small class="f-error nostr-inline">{{ $relay['error'] }}</small>@endif
                                            @elseif (!empty($relay['error']))
                                                <small class="f-error nostr-inline">{{ $relay['error'] }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-fruit::table>
                    </div>
                @endif

                @if (!empty($legacy_count))
                    <x-fruit::alert tone="warning">
                        {{ __(':count message(s) arrived as legacy NIP-04 direct messages, which this channel does not support; the last one :ago from :npub.', ['count' => $legacy_count, 'ago' => $legacy && $legacy->created_at ? $legacy->created_at->diffForHumans() : '', 'npub' => $legacy ? \App\Nostr\Keys::shortNpub($legacy->pubkey) : '']) }}
                        {{ __('Ask the sender to use a client that speaks NIP-17 (Damus 1.18 or newer with legacy DMs off, Amethyst, 0xchat, Primal).') }}
                    </x-fruit::alert>
                @endif

                @if ($lstate != 'disabled')
                    <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="f-row">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="diagnose">
                        <x-fruit::button type="submit" size="small">{{ __('Check Relays') }}</x-fruit::button>
                        <small class="f-muted">{{ __('Asks each relay what it holds for this mailbox (takes up to a minute).') }}</small>
                    </form>
                @endif

                @if (!empty($diagnose['relays']))
                    <div class="f-table__scroll">
                        <x-fruit::table class="nostr-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Relay') }}</th>
                                    <th>{{ __('Connection') }}</th>
                                    <th>{{ __('Gift wraps (3 days)') }}</th>
                                    <th>{{ __('Legacy NIP-04') }}</th>
                                    <th>{{ __('Profile') }}</th>
                                    <th>{{ __('DM relay list') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($diagnose['relays'] as $url => $row)
                                    <tr>
                                        <td><code>{{ $url }}</code>@if (!empty($row['inbox'])) <small class="f-muted">{{ __('inbox') }}</small>@endif</td>
                                        <td>@if ($row['ok'])<x-fruit::badge tone="success">{{ __('ok') }}</x-fruit::badge>@else<x-fruit::badge tone="danger">{{ __('failed') }}</x-fruit::badge> <small class="f-error nostr-inline">{{ $row['error'] }}</small>@endif</td>
                                        <td>{{ $row['wraps'] }}@if ($row['unseen_wraps']) <x-fruit::badge tone="warning">{{ __(':n not yet received', ['n' => $row['unseen_wraps']]) }}</x-fruit::badge>@endif</td>
                                        <td>{{ $row['legacy'] }}</td>
                                        <td>{{ $row['profile'] ? __('found') : __('missing') }}</td>
                                        <td>@if ($row['dm_relays'] === null){{ __('missing') }}@else<small>{{ implode(' ', $row['dm_relays']) }}</small>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-fruit::table>
                    </div>
                    <p class="f-help">{{ __('"Not yet received" gift wraps sit on a relay the listener does not watch or arrived while it was down; add that relay to the inbox relays or wait for the next run. A missing DM relay list on the relays a sender uses means their client cannot find where to deliver: publish the profile again.') }}</p>
                @endif

                @if ($listener['log'] !== '')
                    <x-fruit::disclosure :title="__('Show Listener Log')">
                        <p class="f-muted">storage/logs/nostr-listen.log</p>
                        <pre class="nostr-log">{{ $listener['log'] }}</pre>
                    </x-fruit::disclosure>
                @endif
            </x-fruit::card>

            @if (session('nostr_reveal_nsec'))
                <x-fruit::alert tone="warning">
                    <strong>{{ __('Private key of this mailbox') }}</strong> <small>{{ __('(shown once; store it somewhere safe, anyone who has it can read and send messages as this mailbox)') }}</small>
                    <pre>{{ session('nostr_reveal_nsec') }}</pre>
                </x-fruit::alert>
            @endif

            <h2 class="settings-form__heading">{{ __('Keys') }}</h2>

            @if (!$cfg->pubkey)
                <x-fruit::alert tone="warning">{{ __('No keypair yet. Generate one, or import the private key of an existing Nostr identity.') }}</x-fruit::alert>
                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="generate">
                    <x-fruit::button type="submit" variant="primary">{{ __('Generate Keypair') }}</x-fruit::button>
                </form>
                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="settings-form">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="import">
                    <x-fruit::field :label="__('Import Private Key')" :description="__('nsec or hex. The private key is stored encrypted with the application key.')" control-id="nostr_nsec">
                        <div class="f-input-group">
                            <input type="password" id="nostr_nsec" name="nsec" class="f-input" placeholder="nsec1…" autocomplete="off" aria-describedby="nostr_nsec-description">
                            <button type="submit" class="f-button">{{ __('Import') }}</button>
                        </div>
                    </x-fruit::field>
                </form>
            @else
                <p class="f-help">
                    {{ __('The keypair is the identity customers write to. It is never deleted by accident: replacing it retires the old key, which keeps receiving messages and keeps answering its conversations, and every change below asks for your password.') }}
                </p>

                @if (count($retired_keys))
                    <div class="f-table__scroll">
                        <x-fruit::table class="nostr-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Retired key') }}</th>
                                    <th>{{ __('Used') }}</th>
                                    <th>{{ __('Retired') }}</th>
                                    <th>{{ __('Messages') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($retired_keys as $key)
                                    <tr>
                                        <td><code title="{{ $key->pubkey }}">{{ $key->getNpub() }}</code></td>
                                        <td>{{ $key->key_created_at ? App\User::dateFormat($key->key_created_at, 'M j, Y') : '' }}</td>
                                        <td>{{ $key->retired_at ? App\User::dateFormat($key->retired_at, 'M j, Y') : '' }}</td>
                                        <td>{{ $key->getMessageCount() }}@if ($key->getLastMessageAt()) <small class="f-muted">({{ __('last') }} {{ App\User::dateFormat(\Illuminate\Support\Carbon::parse($key->getLastMessageAt()), 'M j, Y') }})</small>@endif</td>
                                        <td>
                                            <details class="nostr-delete-key">
                                                <summary class="f-button f-button--small">{{ __('Delete…') }}</summary>
                                                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="f-stack">
                                                    {{ csrf_field() }}
                                                    <input type="hidden" name="action" value="delete_key">
                                                    <input type="hidden" name="key_id" value="{{ $key->id }}">
                                                    <span class="f-error">{{ __('Messages still sent to this key will be unreadable forever.') }}</span>
                                                    <input type="password" name="password" class="f-input" placeholder="{{ __('Your Password') }}" aria-label="{{ __('Your Password') }}" autocomplete="current-password" required>
                                                    <input type="text" name="confirm" class="f-input" placeholder="{{ __('Type DELETE') }}" aria-label="{{ __('Type DELETE') }}" autocomplete="off" required>
                                                    <div><x-fruit::button type="submit" variant="danger" size="small">{{ __('Delete Retired Key') }}</x-fruit::button></div>
                                                </form>
                                            </details>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-fruit::table>
                    </div>
                @endif

                <x-fruit::disclosure :title="__('Show Private Key (Backup)…')">
                    <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="f-stack">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="reveal">
                        <p class="f-help">{{ __('Keep a copy of the private key outside this server so the identity survives a lost database or a migration.') }}</p>
                        <div class="f-row">
                            <input type="password" name="password" class="f-input nostr-password" placeholder="{{ __('Your Password') }}" aria-label="{{ __('Your Password') }}" autocomplete="current-password" required>
                            <x-fruit::button type="submit">{{ __('Show Private Key') }}</x-fruit::button>
                        </div>
                    </form>
                </x-fruit::disclosure>

                <x-fruit::disclosure :title="__('Replace the Key…')">
                    <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="settings-form">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="replace">
                        <p class="f-help">{{ __('Customers who saved the current public key can still reach this mailbox afterwards: the current key is retired, not deleted. New customers are pointed to the new key through the profile, the relay lists and the address.') }}</p>
                        <x-fruit::fieldset>
                            <legend>{{ __('New Key') }}</legend>
                            <x-fruit::radio name="replace_mode" value="generate" checked>{{ __('Generate') }}</x-fruit::radio>
                            <x-fruit::radio name="replace_mode" value="import">{{ __('Import') }}</x-fruit::radio>
                        </x-fruit::fieldset>
                        <input type="password" name="nsec" class="f-input" placeholder="{{ __('nsec1… (only when importing)') }}" aria-label="{{ __('Import Private Key') }}" autocomplete="off">
                        <x-fruit::field :label="__('Your Password')">
                            <x-fruit::input type="password" name="password" autocomplete="current-password" required />
                        </x-fruit::field>
                        <x-fruit::field :label="__('Type REPLACE')">
                            <x-fruit::input name="confirm" autocomplete="off" required />
                        </x-fruit::field>
                        <div><x-fruit::button type="submit" variant="danger">{{ __('Replace the Key') }}</x-fruit::button></div>
                    </form>
                </x-fruit::disclosure>
            @endif

            <form id="page-form" class="settings-form" method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}

                <x-fruit::switch name="enabled" value="1" id="nostr_enabled" :checked="(bool) old('enabled', $cfg->enabled)">{{ __('Enable Nostr') }}</x-fruit::switch>

                <h2 class="settings-form__heading">{{ __('Relays') }}</h2>

                <x-fruit::field :label="__('Inbox Relays')" :description="__('One per line. Where customers deliver their messages and where Tallport listens. Keep this list short (1-3 relays); it is published as your DM relay list (kind 10050).')">
                    <x-fruit::textarea id="nostr_inbox_relays" name="inbox_relays" rows="4">{{ old('inbox_relays', implode("\n", $cfg->getInboxRelays())) }}</x-fruit::textarea>
                </x-fruit::field>

                <x-fruit::field :label="__('Announce Relays')" :description="__('One per line. Popular relays where the profile and relay lists of this mailbox are published, and where customer profiles are looked up.')">
                    <x-fruit::textarea id="nostr_announce_relays" name="announce_relays" rows="4">{{ old('announce_relays', implode("\n", $cfg->getAnnounceRelays())) }}</x-fruit::textarea>
                </x-fruit::field>

                <h2 class="settings-form__heading">{{ __('Public Profile') }}</h2>

                <x-fruit::field :label="__('Name')">
                    <x-fruit::input id="nostr_profile_name" name="profile_name" :value="old('profile_name', $cfg->profile_name ?? '')" :placeholder="$mailbox->name" maxlength="255" />
                </x-fruit::field>

                <x-fruit::field :label="__('About')">
                    <x-fruit::textarea id="nostr_profile_about" name="profile_about" rows="3">{{ old('profile_about', $cfg->profile_about ?? '') }}</x-fruit::textarea>
                </x-fruit::field>

                <x-fruit::field :label="__('Picture URL')">
                    <x-fruit::input type="url" id="nostr_profile_picture" name="profile_picture" :value="old('profile_picture', $cfg->profile_picture ?? '')" placeholder="https://" />
                </x-fruit::field>

                <x-fruit::field :label="__('Address')" :description="__('Optional NIP-05 address customers can look up instead of the npub. Any domain works: the domain must serve /.well-known/nostr.json.')">
                    <x-fruit::input id="nostr_nip05" name="nip05" :value="old('nip05', $cfg->nip05 ?? '')" placeholder="support@yourdomain.com" maxlength="255" />
                </x-fruit::field>
                @if ($cfg->getNip05Domain() && $cfg->nip05ServedHere())
                    <p class="settings-form__note"><x-fruit::badge tone="success">{{ __('This installation answers on :domain, so the file is served automatically.', ['domain' => $cfg->getNip05Domain()]) }}</x-fruit::badge></p>
                @endif

                @if ($nip05_json && !$cfg->nip05ServedHere())
                    <x-fruit::card class="f-stack">
                        <h3 class="f-headline">{{ __('Host this file at :url', ['url' => $nip05_url]) }}</h3>
                        <p class="f-help">{{ __('Serve it as application/json with the header Access-Control-Allow-Origin: *. Update it when you change the inbox relays or the key.') }}</p>
                        <pre>{{ $nip05_json }}</pre>
                    </x-fruit::card>
                @endif

                <h2 class="settings-form__heading">{{ __('Conversations') }}</h2>

                <x-fruit::switch name="auto_reply_enabled" value="1" id="nostr_auto_reply_enabled" :checked="(bool) old('auto_reply_enabled', $cfg->auto_reply_enabled)" :description="__('Sent once when a new conversation is started, not when an existing one is reopened.')">{{ __('Auto reply') }}</x-fruit::switch>

                <x-fruit::field :label="__('Auto Reply Text')" :description="__('Plain text. Nostr messages have no formatting.')">
                    <x-fruit::textarea id="nostr_auto_reply_text" name="auto_reply_text" rows="4">{{ old('auto_reply_text', $cfg->auto_reply_text ?? '') }}</x-fruit::textarea>
                </x-fruit::field>

            </form>

            <h2 class="settings-form__heading">{{ __('How It Works') }}</h2>
            <ul class="f-help">
                <li>{{ __('The relay listener runs from the cron job (tallport:nostr-listen). Check storage/logs/nostr-listen.log if messages do not arrive.') }}</li>
                <li>{{ __('Unknown senders become new customers; link additional public keys on the Nostr tab of a customer profile.') }}</li>
                <li>{{ __('Replies are plain text. Attachments are sent as download links.') }}</li>
            </ul>
        </div>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" name="action" value="save" variant="primary">{{ __('Save') }}</x-fruit::button>
    @if ($cfg->pubkey)
        <x-fruit::button type="submit" form="page-form" name="action" value="announce" title="{{ __('Publish the profile and relay lists to the relays now') }}">{{ __('Publish Profile Now') }}</x-fruit::button>
    @endif
@endsection

@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Connection Settings').' - '.$mailbox->name)

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('mailboxes/connection_menu')

        @include('partials/flash_messages')

        <form class="settings-form" method="POST" action="" id="form-fetching" x-data="tallportMailboxIncoming({{ $mailbox->id }})" x-on:change="changed" x-on:keyup="changed">
            {{ csrf_field() }}

            <p class="f-help">
                {!! __("You can read more about fetching emails :%a_begin%here:%a_end%.", ['%a_begin%' => '<a href="'.htmlspecialchars(config('app.freescout_repo')).'/wiki/Fetching-Emails" target="_blank">', '%a_end%' =>'</a>']) !!}
            </p>

            <x-fruit::form-section :title="__('Fetching Emails')">
                <div class="f-form-row">
                    <span class="f-label">{{ __('Status') }}</span>
                    @if ($mailbox->isInActive())
                        <x-fruit::badge tone="success">{{ __('Active') }}</x-fruit::badge>
                    @else
                        <x-fruit::badge tone="warning">{{ __('Inactive') }}</x-fruit::badge>
                    @endif
                </div>

                <x-fruit::field :label="__('Fetch From')" control-id="email" layout="row">
                    <div class="f-row">
                        <input id="email" type="email" class="f-input connection-fetch-from" name="email" value="{{ $mailbox->email }}" disabled="disabled">
                        <a href="{{ route('mailboxes.update', ['id'=>$mailbox->id]) }}#email" class="f-button f-button--ghost f-button--small" title="{{ __('Change address in mailbox settings') }}">{{ __('Change') }}</a>
                    </div>
                </x-fruit::field>

                <x-fruit::field :label="__('Protocol')" layout="row">
                    <x-fruit::select id="in_protocol" name="in_protocol" required>
                        @foreach($mailbox->getInProtocolDisplayNames() as $id => $name)
                            <option value="{{$id}}" @selected(old('in_protocol', $mailbox->in_protocol) == $id)>{{$name}}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Server')" data-in-protocol="default">
                <x-fruit::field :label="__('Server')" layout="row">
                    <x-fruit::input id="in_server" name="in_server" :value="old('in_server', $mailbox->in_server)" maxlength="255" />
                </x-fruit::field>

                <x-fruit::field :label="__('Port')" layout="row">
                    <x-fruit::number id="in_port" name="in_port" :value="old('in_port', $mailbox->in_port)" maxlength="5" required />
                </x-fruit::field>
                @php
                    $in_oauth_enabled = $mailbox->inOauthEnabled();
                @endphp
                <x-fruit::field :label="__('Username')" layout="row">
                    {{-- new-password: no autocomplete in Chrome. --}}
                    <x-fruit::input id="in_username" name="in_username" :value="old('in_username', $mailbox->in_username)" maxlength="100" autocomplete="new-password" :readonly="$in_oauth_enabled" />
                </x-fruit::field>

                <x-fruit::field :label="__('Password')" layout="row">
                    <x-fruit::input type="password" id="in_password" name="in_password" :value="old('in_password', $mailbox->inPasswordSafe())" maxlength="255" autocomplete="new-password" :readonly="$in_oauth_enabled" />
                </x-fruit::field>
                @php
                    $active_oauth_provider = '';
                    if ($in_oauth_enabled) {
                        $active_oauth_provider = $mailbox->oauthGetParam('provider');
                    }
                @endphp
                <div class="f-help">
                    {{-- Microsoft Exchange --}}
                    @if ($active_oauth_provider != \MailHelper::OAUTH_PROVIDER_GOOGLE)
                        <p>
                            @if ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_MICROSOFT))<x-fruit::badge tone="success">Microsoft Exchange</x-fruit::badge>@else Microsoft Exchange @endif
                            @if (!$mailbox->oauthEnabled())
                                @if ($mailbox->in_username && $mailbox->in_password && $mailbox->isInUsernameOauth())
                                     – <a href="{{ route('mailboxes.oauth', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_MICROSOFT, 'in_out' => 'in']) }}" target="_blank">{{ __('Connect') }}</a>
                                @endif
                            @elseif ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_MICROSOFT) && $in_oauth_enabled)
                                 – <a href="{{ route('mailboxes.oauth_disconnect', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_MICROSOFT, 'in_out' => 'in', 'token' => csrf_token()]) }}">{{ __('Disconnect') }}</a>
                            @endif
                            (<a href="{{ config('app.freescout_repo') }}/wiki/Connect-FreeScout-to-Microsoft-365-Exchange-via-oAuth" target="_blank">{{ __('Help') }}</a>)
                        </p>
                    @endif

                    {{-- Google Workspace --}}
                    @if ($active_oauth_provider != \MailHelper::OAUTH_PROVIDER_MICROSOFT)
                        <p>
                            @if ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_GOOGLE))<x-fruit::badge tone="success">Google Workspace</x-fruit::badge>@else Google Workspace @endif
                            @if (!$mailbox->oauthEnabled())
                                @if ($mailbox->in_username && $mailbox->in_password && $mailbox->isInUsernameOauth())
                                     – <a href="{{ route('mailboxes.oauth', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_GOOGLE, 'in_out' => 'in']) }}" target="_blank">{{ __('Connect') }}</a>
                                @endif
                            @elseif ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_GOOGLE) && $in_oauth_enabled)
                                 – <a href="{{ route('mailboxes.oauth_disconnect', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_GOOGLE, 'in_out' => 'in', 'token' => csrf_token()]) }}">{{ __('Disconnect') }}</a>
                            @endif
                            (<a href="{{ config('app.freescout_repo') }}/wiki/Connect-FreeScout-to-Google-Workspace" target="_blank">{{ __('Help') }}</a>)
                        </p>
                    @endif
                </div>

                @php
                    $new_fetching_library = config('app.new_fetching_library');
                    $in_encryption = old('in_encryption', $mailbox->in_encryption);
                    // Set TLS encryption by default.
                    if ($in_encryption == App\Mailbox::IN_ENCRYPTION_NONE) {
                        if (!$mailbox->inSettingsSaved()) {
                            $in_encryption = App\Mailbox::IN_ENCRYPTION_TLS;
                        }
                    }
                @endphp
                <x-fruit::field :label="__('Encryption')" layout="row">
                    <x-fruit::select id="in_encryption" name="in_encryption" :required="$mailbox->out_method == App\Mailbox::OUT_METHOD_SMTP">
                        <option value="{{ App\Mailbox::IN_ENCRYPTION_NONE }}" @selected($in_encryption == App\Mailbox::IN_ENCRYPTION_NONE)>{{ __('None') }}</option>
                        <option value="{{ App\Mailbox::IN_ENCRYPTION_SSL }}" @selected($in_encryption == App\Mailbox::IN_ENCRYPTION_SSL)>SSL</option>
                        <option value="{{ App\Mailbox::IN_ENCRYPTION_TLS }}" @selected($in_encryption == App\Mailbox::IN_ENCRYPTION_TLS)>{{ 'TLS' }}@if (!$new_fetching_library) &nbsp;(+StartTLS)@endif</option>
                        @if ($new_fetching_library)
                            <option value="{{ App\Mailbox::IN_ENCRYPTION_STARTTLS }}" @selected($in_encryption == App\Mailbox::IN_ENCRYPTION_STARTTLS)>TLS &nbsp;(+StartTLS)</option>
                        @endif
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('IMAP Folders')" control-id="in_imap_folders" layout="row">
                    <div class="f-row connection-imap-folders">
                        {{-- One folder per item (posted as in_imap_folders[]); Get folders suggests the server's. --}}
                        <x-fruit::token-field id="in_imap_folders" name="in_imap_folders" submit="list" placeholder="INBOX" x-on:input="document.getElementById('check-connection').disabled = true">{{ implode("\n", $mailbox->getInImapFolders()) }}<x-slot:options></x-slot:options></x-fruit::token-field>
                        <button type="button" class="f-button f-button--ghost f-button--small" title="{{ __('Retrieve a list of available IMAP folders from the server') }}" id="retrieve-imap-folders" x-on:click="retrieveFolders">{{ __('Get Folders') }}</button>
                    </div>
                </x-fruit::field>

                <x-fruit::field :label="__('Validate Certificate')" layout="row">
                    <x-fruit::switch id="in_validate_cert" name="in_validate_cert" value="1" :checked="(bool) old('in_validate_cert', $mailbox->in_validate_cert)" />
                    <x-slot:description>{{ __('Disable certificate validation if receiving "Certificate failure" error.') }}</x-slot:description>
                </x-fruit::field>

                <x-fruit::field :label="__('IMAP Folder To Save Outgoing Replies')" :description="__('Enter IMAP folder name to save outgoing replies if your mail service provider does not do it automatically (Gmail does it), otherwise leave it blank.')" layout="row">
                    <x-fruit::input id="imap_sent_folder" name="imap_sent_folder" :value="old('imap_sent_folder', $mailbox->imap_sent_folder)" maxlength="50" placeholder="Sent" />
                </x-fruit::field>

                @php
                    $after_fetch = App\Incoming\AfterFetch::settings($mailbox);
                    $after_fetch_action = old('after_fetch_action', $after_fetch['action']);
                @endphp
                <x-fruit::field :label="__('After Fetching')" :description="__('IMAP only. Keeps the mail server from filling up: Tallport keeps every email it fetched, with its original source.')" layout="row">
                    <div class="f-stack">
                        <x-fruit::select id="after_fetch_action" name="after_fetch_action">
                            <option value="{{ App\Incoming\AfterFetch::LEAVE }}" @selected($after_fetch_action == App\Incoming\AfterFetch::LEAVE)>{{ __('Mark email as read') }}</option>
                            <option value="{{ App\Incoming\AfterFetch::MOVE }}" @selected($after_fetch_action == App\Incoming\AfterFetch::MOVE)>{{ __('Move to IMAP folder') }}</option>
                            <option value="{{ App\Incoming\AfterFetch::REMOVE }}" @selected($after_fetch_action == App\Incoming\AfterFetch::REMOVE)>{{ __('Remove from the mail server') }}</option>
                        </x-fruit::select>
                        <input type="text" class="f-input @if ($after_fetch_action != App\Incoming\AfterFetch::MOVE) hidden @endif" id="after_fetch_folder" name="after_fetch_folder" value="{{ old('after_fetch_folder', $after_fetch['folder']) }}" maxlength="255" placeholder="{{ __('IMAP Folder') }}" aria-label="{{ __('IMAP Folder') }}">
                        @error('after_fetch_folder')<p class="f-error">{{ $message }}</p>@enderror
                    </div>
                </x-fruit::field>
            </x-fruit::form-section>

            @action('mailbox.connection_incoming.after_default_settings', $mailbox)

            <x-fruit::form-section :title="__('Test')">
                <div class="f-form-row">
                    <p class="f-help">{{ __("Make sure to save settings before checking connection.") }}</p>
                    <button type="button" class="f-button" id="check-connection" x-ref="check" x-on:click="checkConnection" @if (!$mailbox->isOutActive()) disabled="disabled" @endif>{{ __('Check Connection') }}</button>
                </div>
                <pre class="hidden" id="fetch_test_log"></pre>
            </x-fruit::form-section>

        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="form-fetching" variant="primary">{{ __('Save Settings') }}</x-fruit::button>
@endsection

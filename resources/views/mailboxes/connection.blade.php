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

        <form id="page-form" class="settings-form" method="POST" action="" x-data="tallportMailboxConnection({{ $mailbox->id }}, {{ App\Mailbox::OUT_METHOD_SMTP }})" x-on:change="methodChanged">
            {{ csrf_field() }}

            <p class="f-help">
                {!! __h("You can read more about sending emails :%a_begin%here:%a_end%.", ['%a_begin%' => '<a href="'.htmlspecialchars(config('app.freescout_repo')).'/wiki/Sending-emails" target="_blank">', '%a_end%' =>'</a>']) !!}
                {{ __("To send system emails via webmail providers (Gmail, Yahoo, etc) use only SMTP method and make sure that SMTP username is equal to the mailbox email address (:%mailbox_email%), otherwise webmail provider won't send emails.", ['%mailbox_email%' => $mailbox->email]) }}
            </p>

            <x-fruit::form-section :title="__('Sending Emails')">
                <x-fruit::fieldset>
                    <legend>{{ __('Method') }}</legend>
                    <x-fruit::radio name="out_method" :value="App\Mailbox::OUT_METHOD_PHP_MAIL" id="out_method_{{ App\Mailbox::OUT_METHOD_PHP_MAIL }}" :checked="$mailbox->out_method == App\Mailbox::OUT_METHOD_PHP_MAIL">{{ __("PHP's mail() function") }}</x-fruit::radio>
                    <x-fruit::radio name="out_method" :value="App\Mailbox::OUT_METHOD_SENDMAIL" id="out_method_{{ App\Mailbox::OUT_METHOD_SENDMAIL }}" :checked="$mailbox->out_method == App\Mailbox::OUT_METHOD_SENDMAIL">{{ __("Sendmail") }}</x-fruit::radio>
                    <x-fruit::radio name="out_method" :value="App\Mailbox::OUT_METHOD_SMTP" id="out_method_{{ App\Mailbox::OUT_METHOD_SMTP }}" :checked="$mailbox->out_method == App\Mailbox::OUT_METHOD_SMTP">{{ __("SMTP") }}</x-fruit::radio>
                </x-fruit::fieldset>
                <p id="out_method_{{ App\Mailbox::OUT_METHOD_SENDMAIL }}_options" class="f-help out_method_options @if ($mailbox->out_method != App\Mailbox::OUT_METHOD_SENDMAIL) hidden @endif">
                    <strong>{{ __("PHP sendmail path:") }}</strong> {{ $sendmail_path }}
                </p>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('SMTP')" id="out_method_{{ App\Mailbox::OUT_METHOD_SMTP }}_options" :class="'out_method_options'.($mailbox->out_method != App\Mailbox::OUT_METHOD_SMTP ? ' hidden' : '')">
                <x-fruit::field :label="__('SMTP Server')" layout="row">
                    <x-fruit::input id="out_server" name="out_server" :value="old('out_server', $mailbox->out_server)" maxlength="255" :required="$mailbox->out_method == App\Mailbox::OUT_METHOD_SMTP" data-smtp-required="true" />
                </x-fruit::field>
                @if (strstr($mailbox->out_server ?? '', '.gmail.'))
                    <p class="f-help">
                        {!! __h("How to :%link_start%connect Gmail:%link_end% to Tallport.", ['%link_start%' => '<a href="'.htmlspecialchars(config('app.freescout_repo')).'/wiki/Connect-Gmail-to-FreeScout" target="_blank">', '%link_end%' => '</a>']) !!}
                    </p>
                @endif

                <x-fruit::field :label="__('Port')" layout="row">
                    <x-fruit::number id="out_port" name="out_port" :value="old('out_port', $mailbox->out_port)" maxlength="5" :required="$mailbox->out_method == App\Mailbox::OUT_METHOD_SMTP" />
                </x-fruit::field>
                @php
                    $out_oauth_enabled = $mailbox->outOauthEnabled();
                @endphp
                <x-fruit::field :label="__('Username')" layout="row">
                    {{-- new-password: no autocomplete in Chrome. --}}
                    <x-fruit::input id="out_username" name="out_username" :value="old('out_username', $mailbox->out_username)" maxlength="255" autocomplete="new-password" :readonly="$out_oauth_enabled" />
                </x-fruit::field>
                <x-fruit::field :label="__('Password')" layout="row">
                    <x-fruit::input type="password" id="out_password" name="out_password" :value="old('out_password', $mailbox->outPasswordSafe())" maxlength="255" autocomplete="new-password" :readonly="$out_oauth_enabled" />
                </x-fruit::field>
                @php
                    $active_oauth_provider = '';
                    if ($out_oauth_enabled) {
                        $active_oauth_provider = $mailbox->oauthGetParam('provider');
                    }
                @endphp
                <div class="f-help">
                    {{-- Microsoft Exchange --}}
                    @if ($active_oauth_provider != \MailHelper::OAUTH_PROVIDER_GOOGLE)
                        <p>
                            @if ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_MICROSOFT) && $out_oauth_enabled)<x-fruit::badge tone="success">Microsoft Exchange</x-fruit::badge>@else Microsoft Exchange @endif
                            @if (!$mailbox->oauthEnabled())
                                @if ($mailbox->out_username && $mailbox->out_password && $mailbox->isOutUsernameOauth())
                                     – <a href="{{ route('mailboxes.oauth', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_MICROSOFT, 'in_out' => 'out']) }}" target="_blank">{{ __('Connect') }}</a>
                                @endif
                            @elseif ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_MICROSOFT) && $out_oauth_enabled)
                                 – <a href="{{ route('mailboxes.oauth_disconnect', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_MICROSOFT, 'in_out' => 'out', 'token' => csrf_token()]) }}">{{ __('Disconnect') }}</a>
                            @endif
                            (<a href="{{ config('app.freescout_repo') }}/wiki/Connect-FreeScout-to-Microsoft-365-Exchange-via-oAuth" target="_blank">{{ __('Help') }}</a>)
                        </p>
                    @endif

                    {{-- Google Workspace --}}
                    @if ($active_oauth_provider != \MailHelper::OAUTH_PROVIDER_MICROSOFT)
                        <p>
                            @if ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_GOOGLE) && $out_oauth_enabled)<x-fruit::badge tone="success">Google Workspace</x-fruit::badge>@else Google Workspace @endif
                            @if (!$mailbox->oauthEnabled())
                                @if ($mailbox->out_username && $mailbox->out_password && $mailbox->isOutUsernameOauth())
                                     – <a href="{{ route('mailboxes.oauth', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_GOOGLE, 'in_out' => 'out']) }}" target="_blank">{{ __('Connect') }}</a>
                                @endif
                            @elseif ($mailbox->isOauthProvider(\MailHelper::OAUTH_PROVIDER_GOOGLE) && $out_oauth_enabled)
                                 – <a href="{{ route('mailboxes.oauth_disconnect', ['id' => $mailbox->id, 'provider' => \MailHelper::OAUTH_PROVIDER_GOOGLE, 'in_out' => 'out', 'token' => csrf_token()]) }}">{{ __('Disconnect') }}</a>
                            @endif
                            (<a href="{{ config('app.freescout_repo') }}/wiki/Connect-FreeScout-to-Google-Workspace" target="_blank">{{ __('Help') }}</a>)
                        </p>
                    @endif
                </div>
                @php
                    $out_encryption = old('out_encryption', $mailbox->out_encryption);
                    // Set TLS encryption by default.
                    if ($out_encryption == App\Mailbox::OUT_ENCRYPTION_NONE) {
                        if (!$mailbox->outSettingsSaved()) {
                            $out_encryption = App\Mailbox::OUT_ENCRYPTION_TLS;
                        }
                    }
                @endphp
                <x-fruit::field :label="__('Encryption')" layout="row">
                    <x-fruit::select id="out_encryption" name="out_encryption" :required="$mailbox->out_method == App\Mailbox::OUT_METHOD_SMTP" data-smtp-required="true">
                        <option value="{{ App\Mailbox::OUT_ENCRYPTION_NONE }}" @selected($out_encryption == App\Mailbox::OUT_ENCRYPTION_NONE)>{{ __('None') }}</option>
                        <option value="{{ App\Mailbox::OUT_ENCRYPTION_SSL }}" @selected($out_encryption == App\Mailbox::OUT_ENCRYPTION_SSL)>SSL</option>
                        <option value="{{ App\Mailbox::OUT_ENCRYPTION_TLS }}" @selected($out_encryption == App\Mailbox::OUT_ENCRYPTION_TLS)>TLS &nbsp;(+StartTLS)</option>
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Test')">
                <x-fruit::field :label="__('Send Test To')" :description="__('Make sure to save settings before testing.')" control-id="send_test" layout="row">
                    <div class="f-input-group">
                        <input id="send_test" type="email" class="f-input" name="send_test_to" value="{{ old('email', \App\Option::get('send_test_to', $mailbox->email)) }}" maxlength="128" aria-describedby="send_test-description" @if (!$mailbox->isOutActive()) disabled="disabled" @endif>
                        <button id="send-test-trigger" class="f-button" type="button" x-on:click="sendTest" @if (!$mailbox->isOutActive()) disabled="disabled" @endif>{{ __('Send Test') }}</button>
                    </div>
                </x-fruit::field>
                <pre class="hidden" id="send_test_log"></pre>
            </x-fruit::form-section>

        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save Settings') }}</x-fruit::button>
@endsection

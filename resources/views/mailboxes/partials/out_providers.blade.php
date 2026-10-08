{{-- The mailbox's sending services (App\Misc\MailProviders): their settings, shown for the method chosen, and the webhook URL for their delivery events. --}}
@php
    $out_api = App\Misc\MailProviders::mailboxSettings($mailbox);
    $out_api_value = fn ($name) => old('out_api.'.$name, $out_api[$name] ?? '');
    $out_api_secret = fn ($name) => old('out_api.'.$name, \Helper::safePassword($out_api[$name] ?? ''));
@endphp
@foreach (App\Mailbox::OUT_METHOD_PROVIDERS as $out_method => $out_provider)
    <x-fruit::form-section :title="App\Misc\MailProviders::NAMES[$out_provider]" id="out_method_{{ $out_method }}_options" :class="'out_method_options'.($mailbox->out_method != $out_method ? ' hidden' : '')">
        @if ($out_provider == App\Misc\MailProviders::SES)
            <x-fruit::field :label="__('Access Key ID')" layout="row">
                <x-fruit::input name="out_api[ses_key]" :value="$out_api_value('ses_key')" maxlength="128" autocomplete="off" />
            </x-fruit::field>
            <x-fruit::field :label="__('Secret Access Key')" layout="row">
                <x-fruit::input type="password" name="out_api[ses_secret]" :value="$out_api_secret('ses_secret')" maxlength="255" autocomplete="new-password" />
            </x-fruit::field>
            <x-fruit::field :label="__('Region')" layout="row">
                <x-fruit::select name="out_api[ses_region]">
                    @foreach (App\Misc\MailProviders::SES_REGIONS as $region)
                        <option value="{{ $region }}" @selected(($out_api_value('ses_region') ?: 'us-east-1') == $region)>{{ $region }}</option>
                    @endforeach
                </x-fruit::select>
            </x-fruit::field>
        @elseif ($out_provider == App\Misc\MailProviders::MAILGUN)
            <x-fruit::field :label="__('Domain')" layout="row">
                <x-fruit::input name="out_api[mailgun_domain]" :value="$out_api_value('mailgun_domain')" maxlength="255" autocomplete="off" />
            </x-fruit::field>
            <x-fruit::field :label="__('API Key')" layout="row">
                <x-fruit::input type="password" name="out_api[mailgun_secret]" :value="$out_api_secret('mailgun_secret')" maxlength="255" autocomplete="new-password" />
            </x-fruit::field>
            <x-fruit::field :label="__('Region')" layout="row">
                <x-fruit::select name="out_api[mailgun_region]">
                    @foreach (App\Misc\MailProviders::MAILGUN_REGIONS as $region => $region_name)
                        <option value="{{ $region }}" @selected(($out_api_value('mailgun_region') ?: 'us') == $region)>{{ $region_name }}</option>
                    @endforeach
                </x-fruit::select>
            </x-fruit::field>
        @elseif ($out_provider == App\Misc\MailProviders::POSTMARK)
            <x-fruit::field :label="__('Server Token')" layout="row">
                <x-fruit::input type="password" name="out_api[postmark_token]" :value="$out_api_secret('postmark_token')" maxlength="255" autocomplete="new-password" />
            </x-fruit::field>
            <x-fruit::field :label="__('Message Stream')" :description="__('Optional; the default transactional stream when empty.')" layout="row">
                <x-fruit::input name="out_api[postmark_stream]" :value="$out_api_value('postmark_stream')" maxlength="100" placeholder="outbound" autocomplete="off" />
            </x-fruit::field>
        @elseif ($out_provider == App\Misc\MailProviders::RESEND)
            <x-fruit::field :label="__('API Key')" layout="row">
                <x-fruit::input type="password" name="out_api[resend_key]" :value="$out_api_secret('resend_key')" maxlength="255" autocomplete="new-password" />
            </x-fruit::field>
        @endif

        @php
            $webhook_url = $mailbox->getOutWebhookUrl($out_provider);
            $webhook_help = $out_provider == App\Misc\MailProviders::SES
                ? __('In Amazon SES, send bounce and complaint notifications to an Amazon SNS topic and subscribe this URL to it (HTTPS); the subscription is confirmed automatically.')
                : __('Add this URL as a webhook for bounces and complaints in :provider, so that replies that were not delivered are marked.', ['provider' => App\Misc\MailProviders::NAMES[$out_provider]]);
        @endphp
        <x-fruit::field :label="__('Webhook URL')" :description="$webhook_help" control-id="out_webhook_{{ $out_provider }}" layout="row">
            <div class="f-input-group">
                <input id="out_webhook_{{ $out_provider }}" type="text" class="f-input" value="{{ $webhook_url }}" readonly aria-describedby="out_webhook_{{ $out_provider }}-description">
                <x-fruit::copy-button :value="$webhook_url" />
            </div>
        </x-fruit::field>
        @if (isset(App\Misc\MailProviders::WEBHOOK_SETTINGS[$out_provider]))
            @php($webhook_setting = App\Misc\MailProviders::WEBHOOK_SETTINGS[$out_provider])
            <x-fruit::field :label="__('Webhook Signing Key')" layout="row">
                <x-fruit::input type="password" name="out_api[{{ $webhook_setting }}]" :value="$out_api_secret($webhook_setting)" maxlength="255" autocomplete="new-password" />
            </x-fruit::field>
        @endif
    </x-fruit::form-section>
@endforeach

<form id="page-form" class="settings-form" method="POST" action="" x-data="{ driver: @js(old('settings.mail_driver', $settings['mail_driver'])) }">
    {{ csrf_field() }}

    <x-fruit::form-section :title="__('System Emails')" :footer="__('These settings are used to send system emails (alerts to admin and invitation emails to users).').' '.__('If you want to send system emails via webmail providers (Gmail, Yahoo, etc), use only SMTP method and make sure that SMTP username is equal to \'Mail From\', otherwise webmail provider won\'t send emails.')">
        <x-fruit::field :label="__('Mail From')" layout="row">
            <x-fruit::input type="email" name="settings[mail_from]" :value="old('settings.mail_from', $settings['mail_from'])" required autofocus />
        </x-fruit::field>

        <x-fruit::field :label="__('Send Method')" layout="row">
            <x-fruit::select name="settings[mail_driver]" x-model="driver">
                @foreach ($mail_drivers as $mail_driver => $mail_driver_title)
                    <option value="{{ $mail_driver }}" @selected($settings['mail_driver'] == $mail_driver)>{{ $mail_driver_title }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <p class="f-help" x-show="driver == 'sendmail'"><strong>{{ __("PHP sendmail path:") }}</strong> {{ $sendmail_path }}</p>
    </x-fruit::form-section>

    <x-fruit::form-section title="SMTP" x-show="driver == 'smtp'">
        <x-fruit::field :label="__('SMTP Server')" layout="row">
            <x-fruit::input name="settings[mail_host]" :value="old('settings.mail_host', $settings['mail_host'])" maxlength="255" x-bind:required="driver == 'smtp'" />
            @if (strstr($settings['mail_host'] ?? '', '.gmail.'))
                <x-slot:description>{!! __h("Make sure to :%link_start%enable less secure apps:%link_end% in your Google account to send emails from Gmail.", ['%link_start%' => '<a href="https://myaccount.google.com/lesssecureapps?pli=1" target="_blank">', '%link_end%' => '</a>']) !!}</x-slot:description>
            @endif
        </x-fruit::field>
        <x-fruit::field :label="__('Port')" layout="row">
            <x-fruit::number name="settings[mail_port]" :value="old('settings.mail_port', $settings['mail_port'])" x-bind:required="driver == 'smtp'" />
        </x-fruit::field>
        <x-fruit::field :label="__('Username')" layout="row">
            {{-- new-password: no autocomplete in Chrome. --}}
            <x-fruit::input name="settings[mail_username]" :value="old('settings.mail_username', $settings['mail_username'])" maxlength="100" autocomplete="new-password" />
        </x-fruit::field>
        <x-fruit::field :label="__('Password')" layout="row">
            <x-fruit::input type="password" name="settings[mail_password]" :value="old('settings.mail_password', \Helper::safePassword($settings['mail_password']))" maxlength="255" autocomplete="new-password" />
        </x-fruit::field>
        <x-fruit::field :label="__('Encryption')" layout="row">
            <x-fruit::select name="settings[mail_encryption]">
                @foreach ([\MailHelper::MAIL_ENCRYPTION_NONE => __('None'), \MailHelper::MAIL_ENCRYPTION_SSL => 'SSL', \MailHelper::MAIL_ENCRYPTION_TLS => 'TLS'] as $encryption => $encryption_name)
                    <option value="{{ $encryption }}" @selected(old('settings.mail_encryption', $settings['mail_encryption']) == $encryption)>{{ $encryption_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Send Test')" x-data="tallportMailSettings">
        <x-fruit::field :label="__('Send Test To')" :description="__('Make sure to save settings before testing.')" control-id="send_test" layout="row">
            <div class="f-input-group">
                <input id="send_test" type="email" class="f-input" aria-describedby="send_test-description" value="{{ old('email', \App\Option::get('send_test_to')) }}" maxlength="128">
                <button id="send-test-trigger" class="f-button" type="button" x-on:click="sendTest">{{ __('Send Test') }}</button>
            </div>
        </x-fruit::field>
        <pre id="send_test_log" x-show="log" x-text="log" x-cloak></pre>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Fetching Emails')">
        <x-fruit::field :label="__('Fetching Interval')" layout="row">
            <x-fruit::select name="settings[fetch_schedule]">
                <option value="{{ \MailHelper::FETCH_SCHEDULE_EVERY_MINUTE }}" @selected(old('settings.fetch_schedule', $settings['fetch_schedule']) == \MailHelper::FETCH_SCHEDULE_EVERY_MINUTE)>{{ __('Every minute') }}</option>
                @foreach ([\MailHelper::FETCH_SCHEDULE_EVERY_TWO_MINUTES, \MailHelper::FETCH_SCHEDULE_EVERY_THREE_MINUTES, \MailHelper::FETCH_SCHEDULE_EVERY_FIVE_MINUTES, \MailHelper::FETCH_SCHEDULE_EVERY_TEN_MINUTES, \MailHelper::FETCH_SCHEDULE_EVERY_FIFTEEN_MINUTES, \MailHelper::FETCH_SCHEDULE_EVERY_THIRTY_MINUTES] as $schedule)
                    <option value="{{ $schedule }}" @selected(old('settings.fetch_schedule', $settings['fetch_schedule']) == $schedule)>{{ __('Every :number minutes', ['number' => $schedule]) }}</option>
                @endforeach
                <option value="{{ \MailHelper::FETCH_SCHEDULE_HOURLY }}" @selected(old('settings.fetch_schedule', $settings['fetch_schedule']) == \MailHelper::FETCH_SCHEDULE_HOURLY)>{{ __('Hourly') }}</option>
            </x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('No-reply addresses')">
        <x-fruit::field :label="__('Addresses')">
            <x-fruit::textarea name="settings[noreply_emails]" rows="5" placeholder="notifications@example.com&#10;mailer-daemon">{{ old('settings.noreply_emails', $settings['noreply_emails']) }}</x-fruit::textarea>
            <x-slot:description>
                {{ __('Agents are warned when writing to these addresses, and auto replies are not sent to them. One per line: a name before the @ (part of it is enough) or a whole address; * matches anything, a dash also matches an underscore or nothing.') }}
                {{ __('Always included') }}: <code>{{ implode(', ', App\Misc\Noreply::DEFAULT_PATTERNS) }}</code>
            </x-slot:description>
        </x-fruit::field>
    </x-fruit::form-section>

</form>

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

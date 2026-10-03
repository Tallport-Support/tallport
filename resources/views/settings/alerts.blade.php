@php
    $alert_fetch = (bool) old('settings.alert_fetch', $settings['alert_fetch']);
    $alert_logs = (bool) old('settings.alert_logs', $settings['alert_logs']);
    $alert_logs_names = old('settings.alert_logs_names', $settings['alert_logs_names']);
@endphp
<form class="settings-form" method="POST" action="" x-data="{ alertFetch: @js($alert_fetch), alertLogs: @js($alert_logs) }">
    {{ csrf_field() }}

    <h2 class="settings-form__heading">{{ __('Email Alerts For Administrators') }}</h2>

    <x-fruit::switch name="settings[alert_fetch]" value="1" :checked="$alert_fetch" x-model="alertFetch" :description="__('Send alert if application could not fetch emails for a period of time.')">{{ __('Fetching Problems') }}</x-fruit::switch>

    <x-fruit::field :label="__('Check Interval (minutes)')" x-show="alertFetch">
        <x-fruit::number name="settings[alert_fetch_period]" min="5" :value="old('settings.alert_fetch_period', $settings['alert_fetch_period'])" x-bind:required="alertFetch" />
    </x-fruit::field>

    <x-fruit::switch name="settings[alert_logs]" value="1" :checked="$alert_logs" x-model="alertLogs" :description="__('Send new log records by email.')">{{ __('Logs Monitoring') }}</x-fruit::switch>

    <div class="settings-form" x-show="alertLogs">
        <x-fruit::fieldset>
            <legend>{{ __('Logs to monitor') }}</legend>
            @foreach ($logs as $log)
                <x-fruit::checkbox name="settings[alert_logs_names][]" :value="$log" :checked="in_array($log, $alert_logs_names)">{{ App\ActivityLog::getLogTitle($log) }}</x-fruit::checkbox>
            @endforeach
        </x-fruit::fieldset>

        <x-fruit::field :label="__('Check Frequency')">
            <x-fruit::select name="settings[alert_logs_period]">
                @foreach (['hour' => __('Hourly'), 'day' => __('Daily'), 'week' => __('Weekly'), 'month' => __('Monthly')] as $period => $period_name)
                    <option value="{{ $period }}" @selected(old('settings.alert_logs_period', $settings['alert_logs_period']) == $period)>{{ $period_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Fetch Errors Threshold')" :description="__('Suppress transient fetch errors (e.g. brief IMAP connection drops) unless the same error occurs at least this many times within the check frequency window. Set to 1 to disable filtering.')">
            <x-fruit::number name="settings[alert_logs_fetch_min_occurrences]" min="1" :value="old('settings.alert_logs_fetch_min_occurrences', $settings['alert_logs_fetch_min_occurrences'])" />
        </x-fruit::field>
    </div>

    <x-fruit::field :label="__('Extra Recipients')" :description="__('Comma separated emails of extra recipients.')">
        <x-fruit::input name="settings[alert_recipients]" :value="old('settings.alert_recipients', $settings['alert_recipients'])" />
    </x-fruit::field>

    <h2 class="settings-form__heading">{{ __('Default Subscriptions For New Users') }}</h2>

    <div class="user-subscriptions">
        @include('users/subscriptions_table', ['subscriptions_formname' => 'settings[subscription_defaults]'])
    </div>

    <div class="settings-form__actions">
        <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
    </div>
</form>

@section('javascript')
    @parent
    notificationsInit();
@endsection

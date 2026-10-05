@php
    $alert_fetch = (bool) old('settings.alert_fetch', $settings['alert_fetch']);
    $alert_logs = (bool) old('settings.alert_logs', $settings['alert_logs']);
    $alert_logs_names = old('settings.alert_logs_names', $settings['alert_logs_names']);
@endphp
<form id="page-form" class="settings-form" method="POST" action="" x-data="{ alertFetch: @js($alert_fetch), alertLogs: @js($alert_logs) }">
    {{ csrf_field() }}

    <x-fruit::form-section :title="__('Email Alerts For Administrators')">
        <x-fruit::field :label="__('Fetching Problems')" :description="__('Send alert if application could not fetch emails for a period of time.')" layout="row">
            <x-fruit::switch name="settings[alert_fetch]" value="1" :checked="$alert_fetch" x-model="alertFetch" />
        </x-fruit::field>

        <x-fruit::field :label="__('Check Interval (minutes)')" layout="row" x-show="alertFetch">
            <x-fruit::number name="settings[alert_fetch_period]" min="5" :value="old('settings.alert_fetch_period', $settings['alert_fetch_period'])" x-bind:required="alertFetch" />
        </x-fruit::field>

        <x-fruit::field :label="__('Logs Monitoring')" :description="__('Send new log records by email.')" layout="row">
            <x-fruit::switch name="settings[alert_logs]" value="1" :checked="$alert_logs" x-model="alertLogs" />
        </x-fruit::field>

        <x-fruit::fieldset x-show="alertLogs">
            <legend>{{ __('Logs to Monitor') }}</legend>
            @foreach ($logs as $log)
                <x-fruit::checkbox name="settings[alert_logs_names][]" :value="$log" :checked="in_array($log, $alert_logs_names)">{{ App\ActivityLog::getLogTitle($log) }}</x-fruit::checkbox>
            @endforeach
        </x-fruit::fieldset>

        <x-fruit::field :label="__('Check Frequency')" layout="row" x-show="alertLogs">
            <x-fruit::select name="settings[alert_logs_period]">
                @foreach (['hour' => __('Hourly'), 'day' => __('Daily'), 'week' => __('Weekly'), 'month' => __('Monthly')] as $period => $period_name)
                    <option value="{{ $period }}" @selected(old('settings.alert_logs_period', $settings['alert_logs_period']) == $period)>{{ $period_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Fetch Errors Threshold')" :description="__('Suppress transient fetch errors (e.g. brief IMAP connection drops) unless the same error occurs at least this many times within the check frequency window. Set to 1 to disable filtering.')" layout="row" x-show="alertLogs">
            <x-fruit::number name="settings[alert_logs_fetch_min_occurrences]" min="1" :value="old('settings.alert_logs_fetch_min_occurrences', $settings['alert_logs_fetch_min_occurrences'])" />
        </x-fruit::field>

        <x-fruit::field :label="__('Extra Recipients')" :description="__('Comma separated emails of extra recipients.')" layout="row">
            <x-fruit::input name="settings[alert_recipients]" :value="old('settings.alert_recipients', $settings['alert_recipients'])" />
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Default Subscriptions For New Users')">
        <div>
            @include('users/subscriptions_table', ['subscriptions_formname' => 'settings[subscription_defaults]'])
        </div>
    </x-fruit::form-section>

</form>

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

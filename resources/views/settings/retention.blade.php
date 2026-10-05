{{-- Settings » Retention (App\Retention\Retention): how long conversations, customers and logs are kept. --}}
@php
    $retention_preview = App\Retention\Retention::run(true);
    $retention_choice = function ($name) use ($settings) {
        $html = '';
        foreach (App\Retention\Retention::CHOICES[$name] as $value) {
            $label = $value ? App\Retention\Retention::period($name, $value) : __('Off');
            $html .= '<option value="'.$value.'"'.(old('settings.'.$name, $settings[$name]) == $value ? ' selected' : '').'>'.e($label).'</option>';
        }

        return $html;
    };
@endphp
<form id="page-form" class="settings-form" method="POST" action="">
    {{ csrf_field() }}

    <x-fruit::form-section :title="__('Retention')" :footer="__('Deleted conversations stay in backups until those are replaced.')">
        <x-fruit::field :label="__('Delete Old Conversations and Customers')" layout="row">
            <x-fruit::switch name="settings[retention_enabled]" value="1" :checked="(bool) old('settings.retention_enabled', $settings['retention_enabled'])" />
            <x-slot:description>{{ __('Off: only logs are cleaned up. On: every night, what the periods below no longer keep is deleted.') }}</x-slot:description>
        </x-fruit::field>
        {{-- What a run would do now, with the saved settings. --}}
        <div class="f-form-row retention-preview">
            <span>{{ $settings['retention_enabled'] ? __('Next Run') : __('If Switched On Now') }}</span>
            <span class="f-help">{{ __(':expired conversations expire, :deleted are deleted for good, :customers customers are deleted.', [
                'expired'   => $retention_preview['expired'],
                'deleted'   => $retention_preview['deleted'] + $retention_preview['trash'] + $retention_preview['spam'],
                'customers' => $retention_preview['customers'],
            ]) }}</span>
        </div>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Conversations')" :footer="__('A customer who writes again gets their expired conversations back, and all their conversations are kept longer. A conversation or customer on legal hold is never deleted.')">
        <x-fruit::field :label="__('Keep Closed Conversations For')" :description="__('After their last message, or the customer\'s last message in any conversation, whichever is later. Active and Pending conversations are kept.')" layout="row">
            <x-fruit::select name="settings[retention_keep_months]">{!! $retention_choice('retention_keep_months') !!}</x-fruit::select>
        </x-fruit::field>
        <x-fruit::field :label="__('Delete Expired Conversations After')" :description="__('Expired conversations wait in the Deleted folder, where they can be restored.')" layout="row">
            <x-fruit::select name="settings[retention_grace_days]">{!! $retention_choice('retention_grace_days') !!}</x-fruit::select>
        </x-fruit::field>
        <x-fruit::field :label="__('Maximum Age')" :description="__('Conversations whose last message is older expire, even for customers who still write. Off: they are kept as long as the customer stays in touch.')" layout="row">
            <x-fruit::select name="settings[retention_max_age_years]">{!! $retention_choice('retention_max_age_years') !!}</x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Deleted and Spam')">
        <x-fruit::field :label="__('Empty Deleted Folder After')" :description="__('Conversations agents deleted.')" layout="row">
            <x-fruit::select name="settings[retention_trash_days]">{!! $retention_choice('retention_trash_days') !!}</x-fruit::select>
        </x-fruit::field>
        <x-fruit::field :label="__('Delete Spam After')" layout="row">
            <x-fruit::select name="settings[retention_spam_days]">{!! $retention_choice('retention_spam_days') !!}</x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Customers')">
        <x-fruit::field :label="__('Delete Customers Without Conversations After')" :description="__('Counted from their last message. Writing again makes them a customer anew.')" layout="row">
            <x-fruit::select name="settings[retention_customer_months]">{!! $retention_choice('retention_customer_months') !!}</x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Logs')" :footer="__('Logs are cleaned up even when retention is off.')">
        <x-fruit::field :label="__('Outgoing Emails')" layout="row">
            <x-fruit::select name="settings[retention_send_log_months]">{!! $retention_choice('retention_send_log_months') !!}</x-fruit::select>
        </x-fruit::field>
        <x-fruit::field :label="__('Notifications')" layout="row">
            <x-fruit::select name="settings[retention_notification_months]">{!! $retention_choice('retention_notification_months') !!}</x-fruit::select>
        </x-fruit::field>
        <x-fruit::field :label="__('Activity Log')" layout="row">
            <x-fruit::select name="settings[retention_activity_log_days]">{!! $retention_choice('retention_activity_log_days') !!}</x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>
</form>

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

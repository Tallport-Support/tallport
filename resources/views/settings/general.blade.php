@php
    $locked = fn ($option) => App\Misc\DatabaseSettings::lockedByEnv($option);
    $locked_note = fn ($option) => App\Misc\DatabaseSettings::lockedNote($option);
    $custom_number = old('settings.custom_number', $settings['custom_number'] ? 'true' : 'false') === 'true';
    $customer_photos = old('settings.customer_photos', $settings['customer_photos']);
@endphp
<form id="page-form" class="settings-form" method="POST" action="" x-data="{ customNumber: @js($custom_number), customerPhotos: @js($customer_photos) }">
    {{ csrf_field() }}

    <x-fruit::form-section :title="__('General')">
        <x-fruit::field :label="__('Company Name')" layout="row">
            <x-fruit::input name="settings[company_name]" :value="old('settings.company_name', $settings['company_name'])" maxlength="60" required autofocus />
        </x-fruit::field>

        <x-fruit::field :label="__('Default Language')" :description="$locked_note('locale')" layout="row">
            <x-fruit::select name="settings[locale]" required :disabled="$locked('locale')">
                @include('partials/locale_options', ['selected' => old('settings.locale', $settings['locale'])])
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Timezone')" :description="$locked_note('timezone')" layout="row">
            <x-fruit::select name="settings[timezone]" required :disabled="$locked('timezone')">
                @include('partials/timezone_options', ['current_timezone' => old('settings.timezone', \Config::get('app.timezone'))])
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::fieldset>
            <legend>{{ __('Time Format') }}</legend>
            <x-fruit::radio name="settings[time_format]" :value="App\User::TIME_FORMAT_12" :checked="old('settings.time_format', $settings['time_format']) == App\User::TIME_FORMAT_12">{{ __('12-hour clock (e.g. 2:13pm)') }}</x-fruit::radio>
            <x-fruit::radio name="settings[time_format]" :value="App\User::TIME_FORMAT_24" :checked="old('settings.time_format', $settings['time_format']) == App\User::TIME_FORMAT_24 || !$settings['time_format']">{{ __('24-hour clock (e.g. 14:13)') }}</x-fruit::radio>
        </x-fruit::fieldset>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Conversations')">
        <x-fruit::fieldset :disabled="$locked('custom_number')">
            <legend>{{ __('Conversation Number') }}</legend>
            <x-fruit::radio name="settings[custom_number]" value="false" :checked="!$custom_number" x-on:change="customNumber = false">{{ __('Equal to conversation ID') }}</x-fruit::radio>
            <x-fruit::radio name="settings[custom_number]" value="true" :checked="$custom_number" x-on:change="customNumber = true">{{ __('Custom') }}…</x-fruit::radio>
            @if ($locked('custom_number'))
                <p class="f-help">{{ $locked_note('custom_number') }}</p>
            @endif
        </x-fruit::fieldset>

        <x-fruit::field :label="__('Next Conversation #')" :description="__('This number is not visible to customers. It is only used to track conversations within :app_name', ['app_name' => config('app.name')])" layout="row" x-show="customNumber">
            <x-fruit::number name="settings[next_ticket]" :value="old('settings.next_ticket', $settings['next_ticket'])" />
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('User Permissions')" :footer="$locked_note('user_permissions')">
        @foreach (App\User::getUserPermissionsList() as $permission_id)
            <x-fruit::checkbox name="settings[user_permissions][]" :value="$permission_id" :disabled="$locked('user_permissions')" :checked="in_array($permission_id, old('settings.user_permissions', $settings['user_permissions']))">{{ App\User::getUserPermissionName($permission_id) }}</x-fruit::checkbox>
        @endforeach
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Emails to Customers')">
        <x-fruit::field :label="__('Conversation History')" :description="$locked_note('email_conv_history')" layout="row">
            <x-fruit::select name="settings[email_conv_history]" required :disabled="$locked('email_conv_history')">
                @foreach (['none' => __('Do not include previous messages'), 'last' => __('Include the last message'), 'full' => __('Send full conversation history')] as $history => $history_name)
                    <option value="{{ $history }}" @selected(old('settings.email_conv_history', $settings['email_conv_history']) == $history)>{{ $history_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Max. Message Size').' (MB)'" :description="$locked_note('max_message_size')" layout="row">
            <x-fruit::number name="settings[max_message_size]" :value="old('settings.max_message_size', $settings['max_message_size'])" :disabled="$locked('max_message_size')" />
        </x-fruit::field>

        <x-fruit::field :label="__('Open Tracking')" layout="row">
            <x-fruit::switch name="settings[open_tracking]" value="1" :checked="(bool) old('settings.open_tracking', $settings['open_tracking'])" />
        </x-fruit::field>

        <x-fruit::field :label="__('Customer Photos')" :description="__('For customers without a photo of their own. Gravatar receives a hash of their email address; Unavatar receives the address itself.')" layout="row">
            <x-fruit::select name="settings[customer_photos]" x-model="customerPhotos">
                <option value="{{ App\Misc\CustomerPhotos::NONE }}" @selected($customer_photos == App\Misc\CustomerPhotos::NONE)>{{ __('None') }}</option>
                @foreach (App\Misc\CustomerPhotos::SERVICES as $photo_service => $photo_service_name)
                    <option value="{{ $photo_service }}" @selected($customer_photos == $photo_service)>{{ $photo_service_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        {{-- Gravatar's generated images (robots, patterns…), or initials. --}}
        <x-fruit::field :label="__('Without a Photo')" layout="row" x-show="customerPhotos != 'none'">
            <x-fruit::select name="settings[customer_gravatar_default]">
                <option value="">{{ __('Initials') }}</option>
                @foreach (App\Misc\CustomerPhotos::DEFAULTS as $gravatar_default => $gravatar_default_name)
                    <option value="{{ $gravatar_default }}" @selected(old('settings.customer_gravatar_default', $settings['customer_gravatar_default']) == $gravatar_default)>{{ $gravatar_default_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>

        <x-fruit::field :label="__('Spread the Word', ['app_name' => \Config::get('app.name')])" layout="row">
            <x-fruit::switch name="settings[email_branding]" value="1" :checked="(bool) old('settings.email_branding', $settings['email_branding'])" />
            <x-slot:description>{{ __('Add "Powered by :app_name" footer text to the outgoing emails to invite more developers to the project and make the application better.', ['app_name' => \Config::get('app.name')]) }}</x-slot:description>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Notification Emails to Users')">
        <x-fruit::field :label="__('Conversation History')" :description="$locked_note('email_user_history')" layout="row">
            <x-fruit::select name="settings[email_user_history]" required :disabled="$locked('email_user_history')">
                @foreach (['none' => __('Do not include previous messages'), 'last' => __('Include the last message'), 'full' => __('Send full conversation history')] as $history => $history_name)
                    <option value="{{ $history }}" @selected(old('settings.email_user_history', $settings['email_user_history']) == $history)>{{ $history_name }}</option>
                @endforeach
            </x-fruit::select>
        </x-fruit::field>
    </x-fruit::form-section>

    <x-fruit::form-section :title="__('Attachment Reminder')" :footer="__('When a reply contains one of these (one per line) but has no attachment, Tallport asks before sending. Empty: never.')">
        <x-fruit::field :label="__('Words')">
            <x-fruit::textarea name="settings[attachment_reminder_phrases]" rows="4">{{ old('settings.attachment_reminder_phrases', $settings['attachment_reminder_phrases']) }}</x-fruit::textarea>
        </x-fruit::field>
    </x-fruit::form-section>

    @action('settings.general.append', $settings, $errors)

</form>

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

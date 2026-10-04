@include('partials/flash_messages')

@action('customer.edit.before_form', $customer)

<div class="page-content">
    <form class="settings-form" method="POST" action="" enctype="multipart/form-data" x-data="tallportMultiInput" @click="click($event)">
        {{ csrf_field() }}

        <x-fruit::form-section :title="__('Profile')">
            <x-fruit::field :label="__('First Name')" layout="row">
                <x-fruit::input id="first_name" name="first_name" :value="old('first_name', $customer->first_name)" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('Last Name')" layout="row">
                <x-fruit::input id="last_name" name="last_name" :value="old('last_name', $customer->last_name)" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('Company')" layout="row">
                <x-fruit::input id="company" name="company" :value="old('company', $customer->company)" :placeholder="__('(optional)')" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('Job Title')" layout="row">
                <x-fruit::input id="job_title" name="job_title" :value="old('job_title', $customer->job_title)" :placeholder="__('(optional)')" maxlength="100" />
            </x-fruit::field>

            <x-fruit::field :label="__('Photo')" description="(JPG, GIF, PNG)" layout="row">
                <x-fruit::file id="photo_url" name="photo_url" />
            </x-fruit::field>

            <x-fruit::field :label="__('Notes')">
                <x-fruit::textarea id="notes" name="notes" rows="2">{{ old('notes', $customer->notes) }}</x-fruit::textarea>
            </x-fruit::field>
        </x-fruit::form-section>

        <x-fruit::form-section :title="__('Contact')">
            <div class="f-field">
                <span class="f-label">{{ __('Email') }}</span>
                <div class="multi-container">
                    @foreach (old('emails', $emails) as $i => $email)
                        <div class="multi-item">
                            <div class="f-row">
                                <input type="email" class="f-input" name="emails[]" value="{{ $email }}" maxlength="191" aria-label="{{ __('Email') }}">
                                <a href="#" class="f-button f-button--ghost f-button--icon multi-remove" tabindex="-1" aria-label="{{ __('Delete') }}"><x-icon.x class="f-icon" aria-hidden="true" /></a>
                            </div>
                            @if ($errors->has('emails.'.$i))<p class="f-error">{{ $errors->first('emails.'.$i) }}</p>@endif
                        </div>
                    @endforeach
                    <p class="block-help"><a href="#" class="multi-add" tabindex="-1">{{ __('Add an email address') }}</a></p>
                </div>
                @if ($errors->has('email'))<p class="f-error">{{ $errors->first('email') }}</p>@endif
            </div>

            <div class="f-field">
                <span class="f-label">{{ __('Phone') }}</span>
                <div class="multi-container">
                    @foreach ($customer->getPhones(true) as $i => $phone)
                        @if (!empty($phone['type']) && isset($phone['value']))
                            <div class="multi-item">
                                <div class="f-row">
                                    <div class="f-input-group">
                                        <select class="f-input" name="phones[{{ $i }}][type]" aria-label="{{ __('Type') }}">
                                            @foreach(\App\Customer::$phone_types as $phone_type => $name)
                                                <option value="{{$phone_type}}" @selected($phone_type == $phone['type'])>{{ \App\Customer::getPhoneTypeName($phone_type) }}</option>
                                            @endforeach
                                        </select>
                                        <input type="tel" class="f-input" name="phones[{{ $i }}][value]" value="{{ $phone['value'] }}" aria-label="{{ __('Phone') }}">
                                    </div>
                                    <a href="#" class="f-button f-button--ghost f-button--icon multi-remove" tabindex="-1" aria-label="{{ __('Delete') }}"><x-icon.x class="f-icon" aria-hidden="true" /></a>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    <p class="block-help" data-max-i="{{ $i }}"><a href="#" class="multi-add" tabindex="-1">{{ __('Add a phone number') }}</a></p>
                </div>
                @if ($errors->has('phones'))<p class="f-error">{{ $errors->first('phones') }}</p>@endif
            </div>

            <div class="f-field">
                <span class="f-label">{{ __('Website') }}</span>
                <div class="multi-container">
                    @foreach ($customer->getWebsites(true) as $website)
                        <div class="multi-item">
                            <div class="f-row">
                                <input type="url" class="f-input" name="websites[]" value="{{ $website }}" maxlength="100" aria-label="{{ __('Website') }}">
                                <a href="#" class="f-button f-button--ghost f-button--icon multi-remove" tabindex="-1" aria-label="{{ __('Delete') }}"><x-icon.x class="f-icon" aria-hidden="true" /></a>
                            </div>
                        </div>
                    @endforeach
                    <p class="block-help"><a href="#" class="multi-add" tabindex="-1">{{ __('Add a website') }}</a></p>
                </div>
                @if ($errors->has('websites'))<p class="f-error">{{ $errors->first('websites') }}</p>@endif
            </div>

            <div class="f-field">
                <span class="f-label">{{ __('Social Profiles') }}</span>
                <div class="multi-container">
                    @foreach ($customer->getSocialProfiles(true) as $i => $social_profile)
                        @if (isset($social_profile['type']) && isset($social_profile['value']))
                            <div class="multi-item">
                                <div class="f-row">
                                    <div class="f-input-group">
                                        <select class="f-input" name="social_profiles[{{ $i }}][type]" aria-label="{{ __('Type') }}">
                                            <option value=""></option>
                                            @foreach (App\Customer::$social_types as $social_type_id => $social_type_code)
                                                <option value="{{ $social_type_id }}" @selected((int)$social_profile['type'] == $social_type_id)>{{ __(App\Customer::$social_type_names[$social_type_id]) }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" class="f-input" name="social_profiles[{{ $i }}][value]" value="{{ $social_profile['value'] }}" aria-label="{{ __('Social Profiles') }}">
                                    </div>
                                    <a href="#" class="f-button f-button--ghost f-button--icon multi-remove" tabindex="-1" aria-label="{{ __('Delete') }}"><x-icon.x class="f-icon" aria-hidden="true" /></a>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    <p class="block-help" data-max-i="{{ $i }}"><a href="#" class="multi-add" tabindex="-1">{{ __('Add a social profile') }}</a></p>
                </div>
                @if ($errors->has('social_profiles'))<p class="f-error">{{ $errors->first('social_profiles') }}</p>@endif
            </div>
        </x-fruit::form-section>

        <x-fruit::form-section :title="__('Address')">
            <x-fruit::field :label="__('Country')" layout="row">
                <x-fruit::select id="country" name="country">
                    <option value=""></option>
                    @foreach (App\Customer::$countries as $country_code => $country_name)
                        <option value="{{ $country_code }}" @selected(old('country', $customer->country) == $country_code)>{{ __($country_name) }}</option>
                    @endforeach
                </x-fruit::select>
            </x-fruit::field>

            <x-fruit::field :label="__('State')" layout="row">
                <x-fruit::input id="state" name="state" :value="old('state', $customer->state)" :placeholder="__('(optional)')" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('City')" layout="row">
                <x-fruit::input id="city" name="city" :value="old('city', $customer->city)" :placeholder="__('(optional)')" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('Address')" layout="row">
                <x-fruit::input id="address" name="address" :value="old('address', $customer->address)" :placeholder="__('(optional)')" maxlength="255" />
            </x-fruit::field>

            <x-fruit::field :label="__('ZIP')" layout="row">
                <x-fruit::input id="zip" name="zip" :value="old('zip', $customer->zip)" :placeholder="__('(optional)')" maxlength="12" />
            </x-fruit::field>
        </x-fruit::form-section>

        @action('customer.edit.after_fields', $customer, $errors)

        <footer class="f-form-row settings-form__actions">
            <a href="{{ route('customers.merge', ['id' => $customer->id]) }}" class="f-button f-button--ghost">{{ __('Merge') }}</a>
            <x-fruit::button type="submit" variant="primary">
                @if (!empty($save_button_title))
                    {{ $save_button_title }}
                @else
                    {{ __('Save Profile') }}
                @endif
            </x-fruit::button>
        </footer>
    </form>
</div>

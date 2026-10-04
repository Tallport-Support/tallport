@extends('layouts.app')

@section('title', __('User Setup Wizard'))

@section('content')
    <div class="auth-page auth-page--wide">

        @include('auth/banner')

        <x-fruit::card class="auth-card">
            @if (!$user)
                <h1 class="f-title-3 auth-card__title">{{ __('User Setup Problem') }}</h1>
                <p>{{ __('No invite was found. Please contact your administrator to have a new invite email sent.') }}</p>
            @else
                <h1 class="f-title-3 auth-card__title">{{ __('Welcome to :company_name, :first_name!', ['company_name' => App\Option::getCompanyName(), 'first_name' => $user->first_name]) }}</h1>
                <p class="f-muted">{{ __("Let's setup your profile.") }}</p>

                <form class="f-stack" method="POST" action="" enctype="multipart/form-data">
                    {{ csrf_field() }}

                    <x-fruit::field :label="__('Your Email')">
                        <x-fruit::input id="email" type="email" name="email" :value="old('email', $user->email)" maxlength="100" required autofocus />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Create a Password')" :description="__('Your password must be at least 8 characters')">
                        <x-fruit::input id="password" type="password" name="password" :value="old('password')" minlength="8" required />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Confirm Password')">
                        <x-fruit::input id="password_confirmation" type="password" name="password_confirmation" :value="old('password_confirmation')" minlength="8" required />
                    </x-fruit::field>

                    @action('user.setup.before_job_title', $user)

                    <x-fruit::field :label="__('Job Title')">
                        <x-fruit::input id="job_title" name="job_title" :value="old('job_title', $user->job_title)" :placeholder="__('(optional)')" maxlength="100" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Phone Number')">
                        <x-fruit::input id="phone" name="phone" :value="old('phone', $user->phone)" :placeholder="__('(optional)')" maxlength="60" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Timezone')">
                        <x-fruit::select id="timezone" name="timezone" required>
                            @include('partials/timezone_options', ['current_timezone' => old('timezone', $user->timezone)])
                        </x-fruit::select>
                    </x-fruit::field>

                    <x-fruit::fieldset>
                        <legend>{{ __('Time Format') }}</legend>
                        <x-fruit::radio name="time_format" :value="App\User::TIME_FORMAT_12" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_12">{{ __('12-hour clock (e.g. 2:13pm)') }}</x-fruit::radio>
                        <x-fruit::radio name="time_format" :value="App\User::TIME_FORMAT_24" :checked="old('time_format', $user->time_format) == App\User::TIME_FORMAT_24 || !$user->time_format">{{ __('24-hour clock (e.g. 14:13)') }}</x-fruit::radio>
                    </x-fruit::fieldset>

                    @if ($user->photo_url)
                        <div id="user-profile-photo" class="f-row">
                            <x-fruit::avatar :src="$user->getPhotoUrl()" :label="__('Profile Image')" />
                            <a href="#" id="user-photo-delete" data-loading-text="{{ __('Deleting') }}…">{{ __('Delete Photo') }}</a>
                        </div>
                    @endif
                    <x-fruit::field :label="__('Photo')" :description="__('Only visible in :app_name.', ['app_name' => \Config::get('app.name')]).' '.__('Image will be re-sized to 200x200. JPG, GIF, PNG accepted.')">
                        <x-fruit::file id="photo_url" name="photo_url" />
                    </x-fruit::field>

                    <div class="auth-card__actions">
                        <x-fruit::button type="submit" variant="primary">{{ __('Save Profile') }}</x-fruit::button>
                    </div>
                </form>
            @endif
        </x-fruit::card>
    </div>
@endsection

@section('javascript')
    @parent
    userProfileInit();
@endsection

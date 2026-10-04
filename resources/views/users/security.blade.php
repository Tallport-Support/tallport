@extends('layouts.app')

@section('title_full', __('Security').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form">
            @if ($must_turn_on)
                <x-fruit::alert tone="warning">{{ __('Two-factor authentication is required. Turn it on to continue.') }}</x-fruit::alert>
            @endif

            <x-fruit::form-section :title="__('Two-factor authentication')">
                <div class="f-form-row">
                    <p class="f-help">{{ __('After the password, a code from an authenticator app on a phone is asked for.') }}</p>
                    @if ($user->hasEnabledTwoFactorAuthentication())
                        <x-fruit::badge tone="success">{{ __('On') }}</x-fruit::badge>
                    @elseif (!$user->two_factor_secret || !$own)
                        <x-fruit::badge>{{ __('Off') }}</x-fruit::badge>
                    @endif
                </div>

                @if (!$own)
                    <div class="f-form-row">
                        <span class="f-label">{{ __('Passkeys') }}</span>
                        <span>{{ count($passkeys) }}</span>
                    </div>
                    @if ($user->hasEnabledTwoFactorAuthentication() || count($passkeys))
                        <form method="POST" action="{{ route('users.security.reset', ['id' => $user->id]) }}" class="f-form-row">
                            {{ csrf_field() }}
                            <p class="f-help">{{ __('For a user who lost their phone: they log in with their password only, and then set it up again.') }}</p>
                            <x-fruit::button type="submit" variant="danger">{{ __('Reset two-factor authentication and passkeys') }}</x-fruit::button>
                        </form>
                    @endif
                @elseif ($user->hasEnabledTwoFactorAuthentication())
                    @if ($recovery_codes)
                        <div class="f-stack">
                            <strong>{{ __('Recovery codes') }}</strong>
                            <p class="f-help">{{ __('Keep these somewhere safe. Each one logs you in once if you lose your phone.') }}</p>
                            <pre class="two-factor-recovery-codes">{{ implode("\n", $recovery_codes) }}</pre>
                        </div>
                    @endif

                    <div class="f-row">
                        @if (!$recovery_codes)
                            <a href="{{ route('users.security', ['id' => $user->id, 'codes' => 1]) }}" class="f-button">{{ __('Show recovery codes') }}</a>
                        @endif
                        <form method="POST" action="{{ route('two-factor.recovery-codes.store') }}">
                            {{ csrf_field() }}
                            <x-fruit::button type="submit">{{ __('New recovery codes') }}</x-fruit::button>
                        </form>
                        @if (!$required)
                            <form method="POST" action="{{ route('two-factor.disable') }}">
                                {{ csrf_field() }}
                                {{ method_field('DELETE') }}
                                <x-fruit::button type="submit" variant="ghost">{{ __('Turn off') }}</x-fruit::button>
                            </form>
                        @endif
                    </div>

                    @if ($trusted_devices)
                        <form method="POST" action="{{ route('users.security.forget_devices', ['id' => $user->id]) }}" class="f-form-row">
                            {{ csrf_field() }}
                            <span>{{ __('Remembered devices: :count', ['count' => $trusted_devices]) }}</span>
                            <x-fruit::button type="submit" variant="ghost" size="small">{{ __('Forget them') }}</x-fruit::button>
                        </form>
                    @endif
                @elseif ($user->two_factor_secret)
                    {{-- Turned on, to be confirmed with a code. --}}
                    <div class="f-stack">
                        <p>{{ __('Scan this QR code with your authenticator app, or enter the key below.') }}</p>
                        <div class="two-factor-qr-code">{!! $user->twoFactorQrCodeSvg() !!}</div>
                        <p>{{ __('Key') }}: <code>{{ trim(chunk_split(Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' ')) }}</code></p>
                    </div>

                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="f-stack">
                        {{ csrf_field() }}
                        <x-fruit::field :label="__('Enter the code the app shows to finish')" bag="confirmTwoFactorAuthentication" layout="row">
                            <x-fruit::input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                        </x-fruit::field>
                        <div class="f-form-row settings-form__actions">
                            <x-fruit::button type="submit" variant="ghost" form="two_factor_cancel">{{ __('Cancel') }}</x-fruit::button>
                            <x-fruit::button type="submit" variant="primary">{{ __('Confirm') }}</x-fruit::button>
                        </div>
                    </form>
                @else
                    <form method="POST" action="{{ route('two-factor.enable') }}" class="f-form-row">
                        {{ csrf_field() }}
                        <x-fruit::button type="submit" variant="primary">{{ __('Turn on two-factor authentication') }}</x-fruit::button>
                    </form>
                @endif
            </x-fruit::form-section>

            <x-fruit::form-section :title="__('Passkeys')">
                <p class="f-help">{{ __('Sign in with a fingerprint, face or device PIN instead of the password and code.') }}</p>

                @if (count($passkeys))
                    <x-fruit::table>
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Added') }}</th>
                                <th>{{ __('Last used') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($passkeys as $passkey)
                                <tr>
                                    <td>{{ $passkey->name }}</td>
                                    <td>{{ App\User::dateFormat($passkey->created_at, 'M j, Y') }}</td>
                                    <td>{{ $passkey->last_used_at ? App\User::dateFormat($passkey->last_used_at, 'M j, Y H:i') : __('Never') }}</td>
                                    <td>
                                        @if ($own)
                                            <form method="POST" action="{{ route('passkey.destroy', ['passkey' => $passkey->id]) }}">
                                                {{ csrf_field() }}
                                                {{ method_field('DELETE') }}
                                                <x-fruit::button type="submit" size="small" variant="ghost">{{ __('Remove') }}</x-fruit::button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-fruit::table>
                @endif

                @if ($own)
                    <form class="f-form-row" id="passkey-add-form" x-data="tallportPasskeyAdd(@js(route('passkey.registration-options')), @js(route('passkey.store')))" @submit.prevent="add($el)">
                        <input type="text" class="f-input passkey-name" id="passkey-name" maxlength="255" placeholder="{{ __('Name, e.g. Laptop') }}" aria-label="{{ __('Name') }}" required>
                        <button type="submit" class="f-button">{{ __('Add a passkey') }}</button>
                    </form>
                @endif
            </x-fruit::form-section>
        </div>
        @if ($own && !$user->hasEnabledTwoFactorAuthentication() && $user->two_factor_secret)
            <form id="two_factor_cancel" method="POST" action="{{ route('two-factor.disable') }}">
                {{ csrf_field() }}
                {{ method_field('DELETE') }}
            </form>
        @endif
    </div>
@endsection

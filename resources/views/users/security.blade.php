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

            <h2 class="settings-form__heading">{{ __('Two-factor authentication') }}</h2>
            <p class="f-help">{{ __('After the password, a code from an authenticator app on a phone is asked for.') }}</p>

            @if (!$own)
                <p class="f-row">
                    @if ($user->hasEnabledTwoFactorAuthentication())
                        <x-fruit::badge tone="success">{{ __('On') }}</x-fruit::badge>
                    @else
                        <x-fruit::badge>{{ __('Off') }}</x-fruit::badge>
                    @endif
                    <span>{{ __('Passkeys') }}: {{ count($passkeys) }}</span>
                </p>
                @if ($user->hasEnabledTwoFactorAuthentication() || count($passkeys))
                    <form method="POST" action="{{ route('users.security.reset', ['id' => $user->id]) }}" class="f-stack">
                        {{ csrf_field() }}
                        <p class="f-help">{{ __('For a user who lost their phone: they log in with their password only, and then set it up again.') }}</p>
                        <div><x-fruit::button type="submit" variant="danger" data-loading-text="{{ __('Reset two-factor authentication and passkeys') }}…">{{ __('Reset two-factor authentication and passkeys') }}</x-fruit::button></div>
                    </form>
                @endif
            @elseif ($user->hasEnabledTwoFactorAuthentication())
                <p><x-fruit::badge tone="success">{{ __('On') }}</x-fruit::badge></p>

                @if ($recovery_codes)
                    <x-fruit::card class="f-stack">
                        <strong>{{ __('Recovery codes') }}</strong>
                        <p class="f-help">{{ __('Keep these somewhere safe. Each one logs you in once if you lose your phone.') }}</p>
                        <pre class="two-factor-recovery-codes">{{ implode("\n", $recovery_codes) }}</pre>
                    </x-fruit::card>
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
                    <form method="POST" action="{{ route('users.security.forget_devices', ['id' => $user->id]) }}" class="f-row">
                        {{ csrf_field() }}
                        <span>{{ __('Remembered devices: :count', ['count' => $trusted_devices]) }}</span>
                        <x-fruit::button type="submit" variant="ghost" size="small">{{ __('Forget them') }}</x-fruit::button>
                    </form>
                @endif
            @elseif ($user->two_factor_secret)
                {{-- Turned on, to be confirmed with a code. --}}
                <p>{{ __('Scan this QR code with your authenticator app, or enter the key below.') }}</p>
                <div class="two-factor-qr-code">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <p>{{ __('Key') }}: <code>{{ trim(chunk_split(Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' ')) }}</code></p>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="settings-form">
                    {{ csrf_field() }}
                    <x-fruit::field :label="__('Enter the code the app shows to finish')" bag="confirmTwoFactorAuthentication">
                        <x-fruit::input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                    </x-fruit::field>
                    <div class="f-row">
                        <x-fruit::button type="submit" variant="primary">{{ __('Confirm') }}</x-fruit::button>
                        <x-fruit::button type="submit" variant="ghost" form="two_factor_cancel">{{ __('Cancel') }}</x-fruit::button>
                    </div>
                </form>
                <form id="two_factor_cancel" method="POST" action="{{ route('two-factor.disable') }}">
                    {{ csrf_field() }}
                    {{ method_field('DELETE') }}
                </form>
            @else
                <p><x-fruit::badge>{{ __('Off') }}</x-fruit::badge></p>
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    {{ csrf_field() }}
                    <x-fruit::button type="submit" variant="primary">{{ __('Turn on two-factor authentication') }}</x-fruit::button>
                </form>
            @endif

            <h2 class="settings-form__heading">{{ __('Passkeys') }}</h2>
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
                <form class="f-row" id="passkey-add-form">
                    <input type="text" class="f-input passkey-name" id="passkey-name" maxlength="255" placeholder="{{ __('Name, e.g. Laptop') }}" aria-label="{{ __('Name') }}" required>
                    <button type="submit" class="f-button" data-loading-text="{{ __('Add a passkey') }}…">{{ __('Add a passkey') }}</button>
                </form>
            @endif
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    @if ($own)
        passkeysInitAdd('{{ route('passkey.registration-options') }}', '{{ route('passkey.store') }}');
    @endif
@endsection

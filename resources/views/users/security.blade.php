@extends('layouts.app')

@section('title_full', __('Security').' - '.$user->getFullName())

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Security') }}
    </div>

    @include('partials/flash_messages')

    <div class="container">
        <div class="row">
            <div class="col-xs-12 col-md-8 margin-top">

                @if ($must_turn_on)
                    <div class="alert alert-warning">{{ __('Two-factor authentication is required. Turn it on to continue.') }}</div>
                @endif

                <h3>{{ __('Two-factor authentication') }}</h3>
                <p class="text-help">{{ __('After the password, a code from an authenticator app on a phone is asked for.') }}</p>

                @if (!$own)
                    <p>
                        @if ($user->hasEnabledTwoFactorAuthentication())
                            <span class="label label-success">{{ __('On') }}</span>
                        @else
                            <span class="label label-default">{{ __('Off') }}</span>
                        @endif
                        &nbsp;{{ __('Passkeys') }}: {{ count($passkeys) }}
                    </p>
                    @if ($user->hasEnabledTwoFactorAuthentication() || count($passkeys))
                        <form method="POST" action="{{ route('users.security.reset', ['id' => $user->id]) }}" class="margin-top">
                            {{ csrf_field() }}
                            <p class="text-help">{{ __('For a user who lost their phone: they log in with their password only, and then set it up again.') }}</p>
                            <button type="submit" class="btn btn-danger" data-loading-text="{{ __('Reset two-factor authentication and passkeys') }}…">{{ __('Reset two-factor authentication and passkeys') }}</button>
                        </form>
                    @endif
                @elseif ($user->hasEnabledTwoFactorAuthentication())
                    <p><span class="label label-success">{{ __('On') }}</span></p>

                    @if ($recovery_codes)
                        <div class="panel panel-default">
                            <div class="panel-body">
                                <p><strong>{{ __('Recovery codes') }}</strong></p>
                                <p class="text-help">{{ __('Keep these somewhere safe. Each one logs you in once if you lose your phone.') }}</p>
                                <pre class="two-factor-recovery-codes">{{ implode("\n", $recovery_codes) }}</pre>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('users.security', ['id' => $user->id, 'codes' => 1]) }}" class="btn btn-default">{{ __('Show recovery codes') }}</a>
                    @endif

                    <form method="POST" action="{{ route('two-factor.recovery-codes.store') }}" class="inline-block">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-default">{{ __('New recovery codes') }}</button>
                    </form>

                    @if (!$required)
                        <form method="POST" action="{{ route('two-factor.disable') }}" class="inline-block">
                            {{ csrf_field() }}
                            {{ method_field('DELETE') }}
                            <button type="submit" class="btn btn-link">{{ __('Turn off') }}</button>
                        </form>
                    @endif

                    @if ($trusted_devices)
                        <form method="POST" action="{{ route('users.security.forget_devices', ['id' => $user->id]) }}" class="margin-top">
                            {{ csrf_field() }}
                            {{ __('Remembered devices: :count', ['count' => $trusted_devices]) }}
                            <button type="submit" class="btn btn-link">{{ __('Forget them') }}</button>
                        </form>
                    @endif
                @elseif ($user->two_factor_secret)
                    {{-- Turned on, to be confirmed with a code. --}}
                    <p>{{ __('Scan this QR code with your authenticator app, or enter the key below.') }}</p>
                    <div class="two-factor-qr-code">{!! $user->twoFactorQrCodeSvg() !!}</div>
                    <p>{{ __('Key') }}: <code>{{ trim(chunk_split(Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' ')) }}</code></p>

                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="form-inline margin-top">
                        {{ csrf_field() }}
                        <div class="form-group{{ $errors->confirmTwoFactorAuthentication->has('code') ? ' has-error' : '' }}">
                            <label for="code">{{ __('Enter the code the app shows to finish') }}</label>
                            <input id="code" type="text" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                            <button type="submit" class="btn btn-primary">{{ __('Confirm') }}</button>
                            @if ($errors->confirmTwoFactorAuthentication->has('code'))
                                <span class="help-block"><strong>{{ $errors->confirmTwoFactorAuthentication->first('code') }}</strong></span>
                            @endif
                        </div>
                    </form>
                    <form method="POST" action="{{ route('two-factor.disable') }}" class="margin-top">
                        {{ csrf_field() }}
                        {{ method_field('DELETE') }}
                        <button type="submit" class="btn btn-link">{{ __('Cancel') }}</button>
                    </form>
                @else
                    <p><span class="label label-default">{{ __('Off') }}</span></p>
                    <form method="POST" action="{{ route('two-factor.enable') }}">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">{{ __('Turn on two-factor authentication') }}</button>
                    </form>
                @endif

                <h3 class="margin-top-40">{{ __('Passkeys') }}</h3>
                <p class="text-help">{{ __('Sign in with a fingerprint, face or device PIN instead of the password and code.') }}</p>

                @if (count($passkeys))
                    <table class="table table-condensed">
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Added') }}</th>
                            <th>{{ __('Last used') }}</th>
                            <th></th>
                        </tr>
                        @foreach ($passkeys as $passkey)
                            <tr>
                                <td>{{ $passkey->name }}</td>
                                <td>{{ App\User::dateFormat($passkey->created_at, 'M j, Y') }}</td>
                                <td>{{ $passkey->last_used_at ? App\User::dateFormat($passkey->last_used_at, 'M j, Y H:i') : __('Never') }}</td>
                                <td class="text-right">
                                    @if ($own)
                                        <form method="POST" action="{{ route('passkey.destroy', ['passkey' => $passkey->id]) }}">
                                            {{ csrf_field() }}
                                            {{ method_field('DELETE') }}
                                            <button type="submit" class="btn btn-link btn-xs">{{ __('Remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($own)
                    <form class="form-inline" id="passkey-add-form">
                        <input type="text" class="form-control" id="passkey-name" maxlength="255" placeholder="{{ __('Name, e.g. Laptop') }}" required>
                        <button type="submit" class="btn btn-default" data-loading-text="{{ __('Add a passkey') }}…">{{ __('Add a passkey') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    @if ($own)
        passkeysInitAdd('{{ route('passkey.registration-options') }}', '{{ route('passkey.store') }}');
    @endif
@endsection

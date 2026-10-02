@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">

            @include('auth/banner')

            <div class="panel panel-default panel-shaded">
                <div class="panel-body">
                    <form class="form-horizontal margin-top" method="POST" action="{{ route('two-factor.login.store') }}">
                        {{ csrf_field() }}

                        <div class="form-group{{ $errors->has('code') || $errors->has('recovery_code') ? ' has-error' : '' }}">
                            <label for="code" class="col-md-4 control-label two-factor-code">{{ __('Code') }}</label>
                            <label for="recovery_code" class="col-md-4 control-label two-factor-recovery hidden">{{ __('Recovery code') }}</label>

                            <div class="col-md-6">
                                <input id="code" type="text" class="form-control two-factor-code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
                                <input id="recovery_code" type="text" class="form-control two-factor-recovery hidden" name="recovery_code" autocomplete="off">
                                <p class="help-block two-factor-code">{{ __('Enter the code from your authenticator app.') }}</p>

                                @if ($errors->has('code') || $errors->has('recovery_code'))
                                    <span class="help-block">
                                        <strong>{{ $errors->first('code') ?: $errors->first('recovery_code') }}</strong>
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-md-6 col-md-offset-4">
                                <label class="checkbox">
                                    <input type="checkbox" name="remember_device" value="1"> {{ __('Remember this device for :days days', ['days' => App\Auth\TrustedDevices::DAYS]) }}
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-md-8 col-md-offset-4">
                                <button type="submit" class="btn btn-primary">{{ __('Login') }}</button>
                                <a href="#" class="btn btn-link two-factor-code" id="two-factor-use-recovery">{{ __('Use a recovery code') }}</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascript')
    @parent
    $('#two-factor-use-recovery').on('click', function(e) {
        e.preventDefault();
        $('.two-factor-code').addClass('hidden');
        $('.two-factor-recovery').removeClass('hidden');
        $('#code').val('');
        $('#recovery_code').focus();
    });
@endsection

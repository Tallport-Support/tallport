@extends('layouts.app')

@section('title_full', __('Notifications').' - '.$user->first_name.' '.$user->last_name)

@if ($user->id == Auth::user()->id)
    @section('body_attrs')@parent data-own_profile="true" @endsection
@endif

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form method="POST" action="" class="user-subscriptions">
            {{ csrf_field() }}
            <div class="f-table__scroll">
                @include('users/subscriptions_table')
            </div>
            <footer class="f-form-row settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save Notifications') }}</x-fruit::button>
            </footer>
        </form>

        @if ($user->id == Auth::user()->id)
            <x-fruit::dialog name="enable-push" aria-label="{{ __('Browser') }}">
                <div class="f-dialog__body"><img src="{{ asset('img/enable-push.png') }}" alt=""></div>
                <form class="f-dialog__footer" method="dialog"><x-fruit::button type="submit" variant="primary" autofocus>{{ __('Close') }}</x-fruit::button></form>
            </x-fruit::dialog>
        @endif
    </div>
@endsection

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
    </div>
@endsection

@section('javascript')
    @parent
    notificationsInit();
@endsection

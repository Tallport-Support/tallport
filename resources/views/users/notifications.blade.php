@extends('layouts.app')

@section('title', __('Notifications'))

@section('title_full', __('Notifications').' - '.$user->first_name.' '.$user->last_name)

@if ($user->id == Auth::user()->id)
    @section('body_attrs')@parent data-own_profile="true" @endsection
@endif

{{-- One's own Notifications (/notifications), a level beside the sidebar; nobody sets another
     person's. Saved as it changes, the whole form (modules add to the table: notifications_table.*). --}}
@section('drilldown')
    <x-fruit::drilldown-column :title="__('Notifications')" id="user-notifications">
        <x-fruit::page width="medium">
            <form method="POST" action="{{ route('users.notifications.save', ['id' => $user->id]) }}" class="user-subscriptions" x-on:change="Tallport.autoSave($el)">
                {{ csrf_field() }}
                <div class="f-table__scroll">
                    @include('users/subscriptions_table')
                </div>
            </form>

            @if ($user->id == Auth::user()->id)
                <x-fruit::dialog name="enable-push" aria-label="{{ __('Browser') }}">
                    <div class="f-dialog__body"><img src="{{ asset('img/enable-push.png') }}" alt=""></div>
                    <form class="f-dialog__footer" method="dialog"><x-fruit::button type="submit" variant="primary" autofocus>{{ __('Close') }}</x-fruit::button></form>
                </x-fruit::dialog>
            @endif
        </x-fruit::page>
    </x-fruit::drilldown-column>
@endsection

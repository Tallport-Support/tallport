@extends('layouts.app')

@section('title_full', __('User Permissions').' - '.$user->first_name.' '.$user->last_name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form" method="POST" action="">
            {{ csrf_field() }}

            @if (count($mailboxes))
                <x-fruit::form-section :title="__('Mailboxes')">
                    <x-fruit::fieldset id="permissions-fields">
                        <legend>{{ __(':first_name has access to the selected mailboxes:', ['first_name' => $user->first_name]) }}</legend>
                        @foreach ($mailboxes as $mailbox_option)
                            <x-fruit::checkbox name="mailboxes[]" id="mailbox-{{ $mailbox_option->id }}" :value="$mailbox_option->id" :checked="$user_mailboxes->contains($mailbox_option)">{{ $mailbox_option->name }}</x-fruit::checkbox>
                        @endforeach
                    </x-fruit::fieldset>
                    <div class="f-form-row">
                        <div class="f-row">
                            <a href="#" class="f-button f-button--ghost f-button--small sel-all">{{ __('all') }}</a>
                            <a href="#" class="f-button f-button--ghost f-button--small sel-none">{{ __('none') }}</a>
                        </div>
                    </div>
                </x-fruit::form-section>
            @endif

            @if (!$user->isAdmin())
                <x-fruit::form-section :title="__('User Permissions')">
                    @foreach (App\User::getUserPermissionsList() as $permission_id)
                        <x-fruit::checkbox name="user_permissions[]" :value="$permission_id" id="user_permission_{{ $permission_id }}" :checked="$user->hasPermission($permission_id)">@if ($user->hasPermission($permission_id, false) != $user->hasPermission($permission_id))<strong>{{ App\User::getUserPermissionName($permission_id) }}</strong>@else{{ App\User::getUserPermissionName($permission_id) }}@endif</x-fruit::checkbox>
                    @endforeach
                </x-fruit::form-section>
            @endif

            @if (count($mailboxes) || !$user->isAdmin())
                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Save Permissions') }}</x-fruit::button>
                </footer>
            @endif
        </form>
    </div>
@endsection

@section('javascript')
    @parent
    permissionsInit();
@endsection
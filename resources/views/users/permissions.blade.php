@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('User Permissions').' - '.$user->first_name.' '.$user->last_name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form id="page-form" class="settings-form" method="POST" action="">
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
                        <div class="f-row" x-data="{ check(on) { document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = on) } }">
                            <button type="button" class="f-button f-button--ghost f-button--small" @click="check(true)">{{ __('All') }}</button>
                            <button type="button" class="f-button f-button--ghost f-button--small" @click="check(false)">{{ __('None') }}</button>
                        </div>
                    </div>
                </x-fruit::form-section>
            @endif

            @if (!$user->isAdmin())
                {{-- Each permission: the user's own, or the default (Settings » General). --}}
                <x-fruit::form-section :title="__('User Permissions')">
                    @foreach (App\User::getUserPermissionsList() as $permission_id)
                        @php $own_permission = $user->permissions[$permission_id] ?? null; @endphp
                        <x-fruit::field :label="App\User::getUserPermissionName($permission_id)" layout="row">
                            <x-fruit::select id="user_permission_{{ $permission_id }}" name="user_permissions[{{ $permission_id }}]">
                                <option value="">{{ $user->hasPermission($permission_id, false) ? __('Default (Allowed)') : __('Default (Not Allowed)') }}</option>
                                <option value="1" @if ($own_permission !== null && $own_permission) selected @endif>{{ __('Allowed') }}</option>
                                <option value="0" @if ($own_permission !== null && !$own_permission) selected @endif>{{ __('Not Allowed') }}</option>
                            </x-fruit::select>
                        </x-fruit::field>
                    @endforeach
                </x-fruit::form-section>
            @endif

            @if (count($mailboxes) || !$user->isAdmin())
            @endif
        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save Permissions') }}</x-fruit::button>
@endsection

@extends('layouts.app')

@section('title_full', __('Mailbox Permissions').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form method="POST" action="" class="settings-form settings-form--wide">
            {{ csrf_field() }}

            <x-fruit::fieldset id="permissions-fields">
                <legend>{{ __('Selected Users have access to this mailbox:') }}</legend>
                @foreach ($users as $perm_user)
                    <x-fruit::checkbox name="users[]" id="user-{{ $perm_user->id }}" :value="$perm_user->id" :checked="$mailbox_users->contains($perm_user)">{{ $perm_user->first_name }} {{ $perm_user->last_name }}</x-fruit::checkbox>
                @endforeach
            </x-fruit::fieldset>
            <p class="f-help settings-form__note">{{ __('Administrators have access to all mailboxes and are not listed here.') }}</p>
            <div class="f-row">
                <a href="#" class="f-button f-button--ghost f-button--small sel-all">{{ __('all') }}</a>
                <a href="#" class="f-button f-button--ghost f-button--small sel-none">{{ __('none') }}</a>
            </div>

            <div class="settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </div>

            @action('mailbox.permissions.before_access_settings', $mailbox, $mailbox_users, $managers)

            <h2 class="settings-form__heading">{{ __('Access Settings') }}</h2>

            <div class="f-table__scroll">
                <x-fruit::table class="mailbox-access-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>{{ __('Hide from Assign list') }}</th>
                            @foreach (\App\Mailbox::$access_permissions as $perm)
                                <th>{{ \App\Mailbox::getAccessPermissionName($perm) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($managers as $mailbox_user)
                            <tr>
                                <td>
                                    {{ $mailbox_user->getFullName() }}
                                    @if ($mailbox_user->isAdmin())
                                        <x-fruit::badge tone="accent">{{ __('Administrator') }}</x-fruit::badge>
                                    @endif
                                </td>
                                <td><input type="checkbox" class="f-check" name="managers[{{ $mailbox_user->id }}][hide]" value="1" aria-label="{{ $mailbox_user->getFullName() }}: {{ __('Hide from Assign list') }}" @if (!empty($mailbox_user->hide)) checked="checked" @endif></td>
                                @foreach (\App\Mailbox::$access_permissions as $perm)
                                    <td>
                                        @if (!$mailbox_user->isAdmin())
                                            <input type="checkbox" class="f-check" name="managers[{{ $mailbox_user->id }}][access][{{ $perm }}]" value="{{ $perm }}" aria-label="{{ $mailbox_user->getFullName() }}: {{ \App\Mailbox::getAccessPermissionName($perm) }}" @if (!empty($mailbox_user->access) && in_array($perm, json_decode($mailbox_user->access))) checked="checked" @endif @if (Auth::id() == $mailbox_user->id && !Auth::user()->isAdmin()) disabled @endif/>
                                        @else
                                            <input type="checkbox" class="f-check" name="" value="" aria-label="{{ $mailbox_user->getFullName() }}: {{ \App\Mailbox::getAccessPermissionName($perm) }}" checked="checked" disabled />
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </x-fruit::table>
            </div>

            <div class="settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </div>
        </form>
    </div>
@endsection

@section('javascript')
    @parent
    permissionsInit();
@endsection

@extends('layouts.app')

@section('page_width', 'wide')

@section('title_full', __('Mailbox Permissions').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form id="page-form" method="POST" action="" class="settings-form settings-form--wide">
            {{ csrf_field() }}

            <x-fruit::form-section :title="__('Users')" :footer="__('Administrators have access to all mailboxes and are not listed here.')">
                <x-fruit::fieldset id="permissions-fields">
                    <legend>{{ __('Selected Users have access to this mailbox:') }}</legend>
                    @foreach ($users as $perm_user)
                        <x-fruit::checkbox name="users[]" id="user-{{ $perm_user->id }}" :value="$perm_user->id" :checked="$mailbox_users->contains($perm_user)">{{ $perm_user->first_name }} {{ $perm_user->last_name }}</x-fruit::checkbox>
                    @endforeach
                </x-fruit::fieldset>
                <div class="f-row">
                    <button type="button" class="f-button f-button--ghost f-button--small" x-data x-on:click="document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = true)">{{ __('all') }}</button>
                    <button type="button" class="f-button f-button--ghost f-button--small" x-data x-on:click="document.querySelectorAll('#permissions-fields input').forEach(input => input.checked = false)">{{ __('none') }}</button>
                </div>
            </x-fruit::form-section>

            @action('mailbox.permissions.before_access_settings', $mailbox, $mailbox_users, $managers)

            <x-fruit::form-section :title="__('Access Settings')">
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
            </x-fruit::form-section>

        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

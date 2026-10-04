@extends('layouts.app')

@section('page_width', 'medium')

@section('title_full', __('API Keys').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form settings-form--wide">
            <p class="f-help">{{ __('A key lets a program use the REST API as you: it sees and does what you can, in the mailboxes you choose. Send it in the X-FreeScout-API-Key header.') }}</p>

            @if ($new_key)
                <x-fruit::alert tone="success">
                    {{ __('Your new API key. Copy it now: it is not shown again.') }}
                    <input type="text" class="f-input" value="{{ $new_key }}" readonly x-data @click="$el.select()" aria-label="{{ __('API Key') }}">
                </x-fruit::alert>
            @endif

            @if (count($keys))
                <x-fruit::form-section :title="__('API Keys')">
                    <x-fruit::table>
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Key') }}</th>
                                <th>{{ __('Access') }}</th>
                                <th>{{ __('Mailboxes') }}</th>
                                <th>{{ __('Last used') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($keys as $key)
                                <tr>
                                    <td>{{ $key->name }}</td>
                                    <td><code>…{{ $key->token_preview }}</code></td>
                                    <td>@if ($key->canWrite()){{ __('Read and write') }}@else{{ __('Read only') }}@endif</td>
                                    <td>@if ($key->mailboxes){{ $mailboxes->whereIn('id', $key->mailboxes)->pluck('name')->implode(' | ') }}@else{{ __('All') }}@endif</td>
                                    <td>@if ($key->last_used_at){{ App\User::dateFormat($key->last_used_at) }}@else{{ __('Never') }}@endif</td>
                                    <td>
                                        <form method="POST" action="{{ route('users.api_keys.action', ['id' => $user->id]) }}" x-data @submit.prevent="Tallport.confirm({ message: @js(__('Revoke this API key?')), confirm: @js(__('Revoke')), tone: 'danger' }).then(ok => ok && $el.submit())">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="key_id" value="{{ $key->id }}">
                                            <x-fruit::button type="submit" size="small" variant="danger">{{ __('Revoke') }}</x-fruit::button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-fruit::table>
                </x-fruit::form-section>
            @endif

            <form class="settings-form" method="POST" action="{{ route('users.api_keys.action', ['id' => $user->id]) }}">
                {{ csrf_field() }}
                <input type="hidden" name="action" value="create">

                <x-fruit::form-section :title="__('New API key')">
                    <x-fruit::field :label="__('Name')" layout="row">
                        <x-fruit::input id="api_key_name" name="name" maxlength="255" required :placeholder="__('What it is for')" />
                    </x-fruit::field>

                    <x-fruit::fieldset>
                        <legend>{{ __('Access') }}</legend>
                        <x-fruit::radio name="ability" :value="App\Api\ApiKey::ABILITY_READ" checked>{{ __('Read only') }}</x-fruit::radio>
                        <x-fruit::radio name="ability" :value="App\Api\ApiKey::ABILITY_WRITE">{{ __('Read and write') }}</x-fruit::radio>
                    </x-fruit::fieldset>

                    <x-fruit::fieldset>
                        <legend>{{ __('Mailboxes') }}</legend>
                        @foreach ($mailboxes as $mailbox_option)
                            <x-fruit::checkbox name="mailboxes[]" :value="$mailbox_option->id">{{ $mailbox_option->name }}</x-fruit::checkbox>
                        @endforeach
                        <p class="f-help">{{ __('None: all your mailboxes.') }}</p>
                    </x-fruit::fieldset>
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Create API key') }}</x-fruit::button>
                </footer>
            </form>
        </div>
    </div>
@endsection

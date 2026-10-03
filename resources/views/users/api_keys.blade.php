@extends('layouts.app')

@section('title_full', __('API Keys').' - '.$user->getFullName())

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('API Keys') }}
    </div>

    @include('partials/flash_messages')

    <div class="container">
        <div class="row">
            <div class="col-xs-12 col-md-8 margin-top">

                <p class="text-help">{{ __('A key lets a program use the REST API as you: it sees and does what you can, in the mailboxes you choose. Send it in the X-FreeScout-API-Key header.') }}</p>

                @if ($new_key)
                    <div class="alert alert-success">
                        {{ __('Your new API key. Copy it now: it is not shown again.') }}
                        <input type="text" class="form-control margin-top-10" value="{{ $new_key }}" readonly onclick="this.select()">
                    </div>
                @endif

                @if (count($keys))
                    <table class="table table-borderless table-condensed">
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Key') }}</th>
                            <th>{{ __('Access') }}</th>
                            <th>{{ __('Mailboxes') }}</th>
                            <th>{{ __('Last used') }}</th>
                            <th></th>
                        </tr>
                        @foreach ($keys as $key)
                            <tr>
                                <td>{{ $key->name }}</td>
                                <td><code>…{{ $key->token_preview }}</code></td>
                                <td>@if ($key->canWrite()){{ __('Read and write') }}@else{{ __('Read only') }}@endif</td>
                                <td>@if ($key->mailboxes){{ $mailboxes->whereIn('id', $key->mailboxes)->pluck('name')->implode(' | ') }}@else{{ __('All') }}@endif</td>
                                <td>@if ($key->last_used_at){{ App\User::dateFormat($key->last_used_at) }}@else{{ __('Never') }}@endif</td>
                                <td>
                                    <form method="POST" action="{{ route('users.api_keys.action', ['id' => $user->id]) }}" onsubmit="return confirm({{ json_encode(__('Revoke this API key?')) }});">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="key_id" value="{{ $key->id }}">
                                        <button type="submit" class="btn btn-link btn-xs text-danger">{{ __('Revoke') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                <h3>{{ __('New API key') }}</h3>
                <form method="POST" action="{{ route('users.api_keys.action', ['id' => $user->id]) }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="create">
                    <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                        <label for="api_key_name">{{ __('Name') }}</label>
                        <input id="api_key_name" type="text" class="form-control input-sized" name="name" maxlength="255" required placeholder="{{ __('What it is for') }}">
                        @include('partials/field_error', ['field' => 'name'])
                    </div>
                    <div class="form-group">
                        <label>{{ __('Access') }}</label>
                        <div>
                            <label class="radio-inline"><input type="radio" name="ability" value="{{ App\Api\ApiKey::ABILITY_READ }}" checked> {{ __('Read only') }}</label>
                            <label class="radio-inline"><input type="radio" name="ability" value="{{ App\Api\ApiKey::ABILITY_WRITE }}"> {{ __('Read and write') }}</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>{{ __('Mailboxes') }}</label>
                        <div>
                            @foreach ($mailboxes as $mailbox)
                                <label class="checkbox-inline"><input type="checkbox" name="mailboxes[]" value="{{ $mailbox->id }}"> {{ $mailbox->name }}</label>
                            @endforeach
                        </div>
                        <div class="form-help">{{ __('None: all your mailboxes.') }}</div>
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Create API key') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection

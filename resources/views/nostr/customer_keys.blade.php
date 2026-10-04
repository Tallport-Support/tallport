@extends('layouts.app')

@section('title_full', $customer->getFullName(true).' - '.__('Nostr'))
@section('body_class', 'sidebar-no-height')
@section('main_class', 'fruit-ui')

@section('body_attrs')@parent data-customer_id="{{ $customer->id }}"@endsection

@section('aside')
    <div class="profile-preview">
        @include('customers/profile_menu')
        @include('customers/profile_snippet')
    </div>
@endsection

@section('content')
    @include('customers/profile_tabs')

    <div class="page-content">
        @include('partials/flash_messages')

        <div class="settings-form settings-form--wide">
            <x-fruit::form-section :title="__('Nostr')" :footer="__('Messages from any of these public keys are added to this customer\'s Nostr conversations. Replies go to the key that wrote last.')">
                @if (count($keys))
                    <table class="f-table nostr-keys-table">
                        <thead>
                            <tr>
                                <th>{{ __('Label') }}</th>
                                <th>{{ __('Public key') }}</th>
                                <th>{{ __('Source') }}</th>
                                <th>{{ __('Last message') }}</th>
                                <th><span class="f-sr-only">{{ __('Remove') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($keys as $key)
                                <tr>
                                    <td>
                                        <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="action" value="label">
                                            <input type="hidden" name="key_id" value="{{ $key->id }}">
                                            <div class="f-input-group">
                                                <input type="text" name="label" class="f-input" value="{{ $key->label ?? '' }}" placeholder="{{ __('e.g. iPhone app') }}" maxlength="255" aria-label="{{ __('Label') }}">
                                                <button type="submit" class="f-button f-button--icon" title="{{ __('Save label') }}" aria-label="{{ __('Save label') }}"><x-heroicon-o-check class="f-icon" aria-hidden="true" /></button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <code title="{{ $key->pubkey }}">{{ $key->getNpub() }}</code>
                                        @if ($key->getDisplayName())<br><small class="f-muted">{{ $key->getDisplayName() }}</small>@endif
                                    </td>
                                    <td>{{ $key->source }}</td>
                                    <td>{{ $key->last_seen_at ? App\User::dateFormat($key->last_seen_at) : '' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}" x-data @submit.prevent="Tallport.confirm({ message: @js(__('Remove this key from the customer?')), confirm: @js(__('Remove')), tone: 'danger' }).then(ok => ok && $el.submit())">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="key_id" value="{{ $key->id }}">
                                            <button type="submit" class="f-button f-button--ghost f-button--icon" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><x-heroicon-o-x-mark class="f-icon" aria-hidden="true" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="f-help">{{ __('No Nostr public keys are linked to this customer yet.') }}</p>
                @endif
            </x-fruit::form-section>

            <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}" class="settings-form settings-form--wide">
                {{ csrf_field() }}
                <input type="hidden" name="action" value="add">
                <x-fruit::form-section :title="__('Link a public key')">
                    <x-fruit::field :label="__('Public key')" :description="__('npub, nprofile or 64 character hex key.')" layout="row">
                        <x-fruit::input id="nostr_pubkey" name="pubkey" placeholder="npub1…" required />
                    </x-fruit::field>
                    <x-fruit::field :label="__('Label')" layout="row">
                        <x-fruit::input id="nostr_label" name="label" :placeholder="__('e.g. Personal, Android app')" maxlength="255" />
                    </x-fruit::field>
                </x-fruit::form-section>
                <footer class="f-form-row settings-form__actions">
                    <x-fruit::button type="submit" variant="primary">{{ __('Add key') }}</x-fruit::button>
                </footer>
            </form>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title_full', __('Preferences').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        <form class="settings-form" method="POST" action="{{ route('users.preferences.save', ['id' => $user->id]) }}">
            {{ csrf_field() }}

            {{-- The status after a reply: unset, the mailbox decides. After sending: the next active conversation, unless set. --}}
            <x-fruit::form-section :title="__('Replies')">
                <x-fruit::field :label="__('Status after a reply')" layout="row">
                    <x-fruit::select id="reply_status" name="reply_status">
                        <option value="">{{ __('As the mailbox is set') }}</option>
                        @foreach ([App\Conversation::STATUS_ACTIVE, App\Conversation::STATUS_PENDING, App\Conversation::STATUS_CLOSED] as $status)
                            <option value="{{ $status }}" @if (old('reply_status', $user->reply_status) == $status) selected @endif>{{ App\Conversation::statusCodeToName($status) }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('After sending')" layout="row">
                    <x-fruit::select id="after_send" name="after_send">
                        <option value="{{ App\MailboxUser::AFTER_SEND_STAY }}" @if (old('after_send', $user->after_send) == App\MailboxUser::AFTER_SEND_STAY) selected @endif>{{ __('Stay on the same page') }}</option>
                        <option value="{{ App\MailboxUser::AFTER_SEND_NEXT }}" @if (old('after_send', $user->afterSend()) == App\MailboxUser::AFTER_SEND_NEXT) selected @endif>{{ __('Next active conversation') }}</option>
                        <option value="{{ App\MailboxUser::AFTER_SEND_FOLDER }}" @if (old('after_send', $user->after_send) == App\MailboxUser::AFTER_SEND_FOLDER) selected @endif>{{ __('Back to folder') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

            <footer class="f-form-row settings-form__actions">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </footer>
        </form>
    </div>
@endsection

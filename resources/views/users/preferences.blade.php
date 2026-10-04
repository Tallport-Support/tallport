@extends('layouts.app')

@section('page_width', 'narrow')

@section('title_full', __('Preferences').' - '.$user->getFullName())

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        <form id="page-form" class="settings-form" method="POST" action="{{ route('users.preferences.save', ['id' => $user->id]) }}">
            {{ csrf_field() }}

            {{-- Unset: Pending after a reply, then the next active conversation. --}}
            <x-fruit::form-section :title="__('Replies')">
                <x-fruit::field :label="__('Status after a reply')" layout="row">
                    <x-fruit::select id="reply_status" name="reply_status">
                        @foreach ([App\Conversation::STATUS_ACTIVE, App\Conversation::STATUS_PENDING, App\Conversation::STATUS_CLOSED] as $status)
                            <option value="{{ $status }}" @if (old('reply_status', $user->replyStatus()) == $status) selected @endif>{{ App\Conversation::statusCodeToName($status) }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::field :label="__('After sending')" layout="row">
                    <x-fruit::select id="after_send" name="after_send">
                        <option value="{{ App\MailboxUser::AFTER_SEND_STAY }}" @if (old('after_send', $user->afterSend()) == App\MailboxUser::AFTER_SEND_STAY) selected @endif>{{ __('Stay on the conversation') }}</option>
                        <option value="{{ App\MailboxUser::AFTER_SEND_NEXT }}" @if (old('after_send', $user->afterSend()) == App\MailboxUser::AFTER_SEND_NEXT) selected @endif>{{ __('Next active conversation') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>

        </form>
    </div>
@endsection

@section('page_footer')
    <x-fruit::button type="submit" form="page-form" variant="primary">{{ __('Save') }}</x-fruit::button>
@endsection

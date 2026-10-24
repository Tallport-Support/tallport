@extends('mailboxes/layout_page')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}"@endsection

{{-- A mailbox's General settings (mailboxes/layout_page), saved with its button (its name and image go to
     customers). Its email is a channel of its own (mailboxes/email). --}}
@section('content')
    @action('mailbox.update.before_mailbox_name', $mailbox)
    @include('partials/flash_messages')
    <form id="mailbox-form" class="settings-form" method="POST" action="{{ route('mailboxes.update.save', ['id' => $mailbox->id]) }}" enctype="multipart/form-data">
        {{ csrf_field() }}

        @action('mailbox.update.before_name', $mailbox, $errors)

        <x-fruit::form-section :title="__('Mailbox')" :level="3">
            @if (Auth::user()->isAdmin())
                <x-fruit::field :label="__('Mailbox Name')" layout="row">
                    <x-fruit::input id="name" name="name" :value="old('name', $mailbox->name)" maxlength="40" required />
                </x-fruit::field>

                <x-fruit::field :label="__('Archived')" layout="row">
                    <x-fruit::switch id="mailbox_state" name="state" :value="App\Mailbox::STATE_ARCHIVED" :checked="old('state', $mailbox->state) == App\Mailbox::STATE_ARCHIVED" />
                </x-fruit::field>
            @else
                <div class="f-form-row">
                    <span class="f-label">{{ __('Mailbox Name') }}</span>
                    <span>{{ old('name', $mailbox->name) }}</span>
                </div>
            @endif

            @if (Auth::user()->can('updateSettings', $mailbox))
                {{-- Its color: the mark on its conversations in views across mailboxes, and its sidebar icon. --}}
                <div class="f-form-row">
                    <div>
                        <span class="f-label" id="mailbox-accent-label">{{ __('Color') }}</span>
                        <p class="f-help">{{ __('Marks this mailbox\'s conversations in views across mailboxes and its icon in the sidebar.') }}</p>
                    </div>
                    <x-fruit::accent-picker name="accent" :value="in_array(old('accent'), FruitUI\Fruit::ACCENTS) ? old('accent') : ($mailbox->accent ?: 'blue')" :label="__('Color')" />
                </div>

                {{-- Its image: what customers see beside its name and its own messages in chats. --}}
                @if ($mailbox->photo_url)
                    <div class="f-form-row">
                        <span>{{ __('Image') }}</span>
                        <span class="f-row">
                            <x-fruit::avatar :src="$mailbox->getPhotoUrl()" :label="__('Image')" />
                            <x-fruit::checkbox id="photo_remove" name="photo_remove" value="1">{{ __('Remove') }}</x-fruit::checkbox>
                        </span>
                    </div>
                @endif
                <x-fruit::field :label="$mailbox->photo_url ? __('Replace Image') : __('Image')" :description="__('Shown to customers in the website chat, such as your logo. JPG, GIF or PNG, cropped to a square.')" layout="row">
                    <x-fruit::file id="photo" name="photo" accept="image/jpeg,image/png,image/gif" />
                </x-fruit::field>

            @endif
        </x-fruit::form-section>

        @if (Auth::user()->can('updateSettings', $mailbox))
            <x-fruit::form-section :title="__('Conversations')" :level="3">
                <x-fruit::field :label="__('Default Assignee')" layout="row">
                    <x-fruit::select id="ticket_assignee" name="ticket_assignee" required>
                        <option value="{{ App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT)>{{ __('Keep Current') }}</option>
                        <option value="{{ App\Mailbox::TICKET_ASSIGNEE_ANYONE }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_ANYONE)>{{ __('Anyone') }}</option>
                        <option value="{{ App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED)>{{ __('Person Replying (if Unassigned)') }}</option>
                        <option value="{{ App\Mailbox::TICKET_ASSIGNEE_REPLYING }}" @selected(old('ticket_assignee', $mailbox->ticket_assignee) == App\Mailbox::TICKET_ASSIGNEE_REPLYING)>{{ __('Person Replying') }}</option>
                    </x-fruit::select>
                </x-fruit::field>
            </x-fruit::form-section>
        @endif


        @can('updateSettings', $mailbox)
            <x-fruit::form-section :title="__('Chat')" :level="3" id="chat">
                <x-fruit::checkbox id="chat_start_new" name="chat_start_new" value="1" :checked="(bool) old('chat_start_new', $mailbox->getMeta('chat_start_new'))">{{ __('Start a new conversation when receiving a reply to the closed / deleted Chat conversation') }}</x-fruit::checkbox>
                <x-fruit::field :label="__('Reopen Window')" :description="__('A new message continues the latest chat conversation if it had activity within this many days; otherwise a new conversation is started.')" control-id="chat_reopen_days">
                    <x-fruit::number id="chat_reopen_days" name="chat_reopen_days" :value="old('chat_reopen_days', App\Misc\ChatConversations::reopenDays($mailbox))" min="1" max="3650" required />
                </x-fruit::field>
            </x-fruit::form-section>
        @endcan

        <footer class="f-form-row settings-form__actions">
            <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
        </footer>
    </form>
@endsection

@extends('layouts.app')

@section('title_full', ($saved_reply->exists ? $saved_reply->name : __('New Saved Reply')).' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        <form class="settings-form" method="POST" action="{{ route('mailboxes.saved_replies.save', ['id' => $mailbox->id]) }}" enctype="multipart/form-data">
            {{ csrf_field() }}
            <input type="hidden" name="saved_reply_id" value="{{ $saved_reply->id }}">

            <x-fruit::form-section :title="$saved_reply->exists ? $saved_reply->name : __('New Saved Reply')">
                <x-fruit::field :label="__('Name')" layout="row">
                    <x-fruit::input id="name" name="name" :value="old('name', $saved_reply->name)" :maxlength="App\SavedReply::NAME_MAX_LENGTH" required autofocus />
                </x-fruit::field>

                <x-fruit::field :label="__('Reply')" :description="__('Variables (Insert variable) are filled in for the conversation.')">
                    <x-fruit::textarea id="saved_reply_text" name="text" rows="8">{{ old('text', $saved_reply->text) }}</x-fruit::textarea>
                </x-fruit::field>

                <div class="f-field">
                    <span class="f-label">{{ __('Attachments') }}</span>
                    @foreach ($attachments as $attachment)
                        <div class="f-row">
                            <a href="{{ $attachment->url() }}" target="_blank">{{ $attachment->file_name }}</a> <span class="f-muted">({{ $attachment->getSizeName() }})</span>
                            <x-fruit::checkbox name="remove_attachments[]" :value="$attachment->id">{{ __('Remove') }}</x-fruit::checkbox>
                        </div>
                    @endforeach
                    <x-fruit::file name="files[]" multiple :aria-label="__('Attachments')" />
                </div>

                <x-fruit::field :label="__('Category')" :description="__('Under another saved reply, which becomes a category.')" layout="row">
                    <x-fruit::select id="parent_saved_reply_id" name="parent_saved_reply_id">
                        <option value="">—</option>
                        @foreach (App\SavedReply::tree($parents) as [$parent, $depth])
                            <option value="{{ $parent->id }}" @selected(old('parent_saved_reply_id', $saved_reply->parent_saved_reply_id) == $parent->id)>{{ str_repeat('  ', $depth) }}{{ $parent->name }}</option>
                        @endforeach
                    </x-fruit::select>
                </x-fruit::field>

                <x-fruit::checkbox name="global" value="1" :checked="(bool) old('global', $saved_reply->global)" :description="__('Available in every mailbox (with what is under it)')">{{ __('Global') }}</x-fruit::checkbox>

                <x-fruit::checkbox name="auto_load" value="1" :checked="(bool) old('auto_load', $saved_reply->auto_load)" :description="__('Put in every new reply of this mailbox')">{{ __('Default reply') }}</x-fruit::checkbox>
            </x-fruit::form-section>

            @if ($saved_reply->exists)
                <x-fruit::form-section>
                    <div class="f-form-row">
                        <p class="f-help">{{ __('Delete this saved reply?') }}</p>
                        <x-fruit::button type="submit" variant="danger" form="saved_reply_delete">{{ __('Delete') }}</x-fruit::button>
                    </div>
                </x-fruit::form-section>
            @endif

            <footer class="f-form-row settings-form__actions">
                <a href="{{ route('mailboxes.saved_replies', ['id' => $mailbox->id]) }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
            </footer>
        </form>

        @if ($saved_reply->exists)
            <form id="saved_reply_delete" method="POST" action="{{ route('mailboxes.saved_replies.delete', ['id' => $mailbox->id, 'saved_reply_id' => $saved_reply->id]) }}" onsubmit="return confirm({{ json_encode(__('Delete this saved reply?')) }});">
                {{ csrf_field() }}
            </form>
        @endif
    </div>
@endsection

@include('partials/editor')

@section('javascript')
    @parent
    savedReplyFormInit();
@endsection

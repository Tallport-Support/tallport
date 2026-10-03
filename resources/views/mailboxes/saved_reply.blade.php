@extends('layouts.app')

@section('title_full', ($saved_reply->exists ? $saved_reply->name : __('New Saved Reply')).' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        <a href="{{ route('mailboxes.saved_replies', ['id' => $mailbox->id]) }}">{{ __('Saved Replies') }}</a> » @if ($saved_reply->exists){{ $saved_reply->name }}@else{{ __('New Saved Reply') }}@endif
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <form class="form-horizontal margin-top" method="POST" action="{{ route('mailboxes.saved_replies.save', ['id' => $mailbox->id]) }}" enctype="multipart/form-data">
            {{ csrf_field() }}
            <input type="hidden" name="saved_reply_id" value="{{ $saved_reply->id }}">

            <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                <label for="name" class="col-sm-2 control-label">{{ __('Name') }}</label>
                <div class="col-sm-6">
                    <input id="name" type="text" class="form-control input-sized" name="name" value="{{ old('name', $saved_reply->name) }}" maxlength="{{ App\SavedReply::NAME_MAX_LENGTH }}" required autofocus>
                    @include('partials/field_error', ['field' => 'name'])
                </div>
            </div>

            <div class="form-group">
                <label for="saved_reply_text" class="col-sm-2 control-label">{{ __('Reply') }}</label>
                <div class="col-sm-9">
                    <textarea id="saved_reply_text" class="form-control" name="text" rows="8">{{ old('text', $saved_reply->text) }}</textarea>
                    <div class="form-help">{{ __('Variables (Insert variable) are filled in for the conversation.') }}</div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">{{ __('Attachments') }}</label>
                <div class="col-sm-6">
                    @foreach ($attachments as $attachment)
                        <div class="checkbox">
                            <a href="{{ $attachment->url() }}" target="_blank">{{ $attachment->file_name }}</a> <span class="text-help">({{ $attachment->getSizeName() }})</span>
                            <label class="margin-left-10"><input type="checkbox" name="remove_attachments[]" value="{{ $attachment->id }}"> {{ __('Remove') }}</label>
                        </div>
                    @endforeach
                    <input type="file" name="files[]" multiple class="margin-top-10">
                </div>
            </div>

            <div class="form-group">
                <label for="parent_saved_reply_id" class="col-sm-2 control-label">{{ __('Category') }}</label>
                <div class="col-sm-6">
                    <select id="parent_saved_reply_id" name="parent_saved_reply_id" class="form-control input-sized">
                        <option value="">—</option>
                        @foreach (App\SavedReply::tree($parents) as [$parent, $depth])
                            <option value="{{ $parent->id }}" @if (old('parent_saved_reply_id', $saved_reply->parent_saved_reply_id) == $parent->id) selected @endif>{{ str_repeat('  ', $depth) }}{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-help">{{ __('Under another saved reply, which becomes a category.') }}</div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">{{ __('Global') }}</label>
                <div class="col-sm-6">
                    <div class="checkbox"><label><input type="checkbox" name="global" value="1" @if (old('global', $saved_reply->global)) checked @endif> {{ __('Available in every mailbox (with what is under it)') }}</label></div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">{{ __('Default reply') }}</label>
                <div class="col-sm-6">
                    <div class="checkbox"><label><input type="checkbox" name="auto_load" value="1" @if (old('auto_load', $saved_reply->auto_load)) checked @endif> {{ __('Put in every new reply of this mailbox') }}</label></div>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-6 col-sm-offset-2">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    <a href="{{ route('mailboxes.saved_replies', ['id' => $mailbox->id]) }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>

        @if ($saved_reply->exists)
            <form method="POST" action="{{ route('mailboxes.saved_replies.delete', ['id' => $mailbox->id, 'saved_reply_id' => $saved_reply->id]) }}" onsubmit="return confirm({{ json_encode(__('Delete this saved reply?')) }});">
                {{ csrf_field() }}
                <div class="col-sm-offset-2"><button type="submit" class="btn btn-link text-danger">{{ __('Delete') }}</button></div>
            </form>
        @endif
    </div>
@endsection

@include('partials/editor')

@section('javascript')
    @parent
    savedReplyFormInit();
@endsection

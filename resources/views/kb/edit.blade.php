@extends('layouts.app')

@section('title', $article->exists ? $article->title : __('New Article'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('kb/sidebar_menu', ['category' => (string) $article->category])
@endsection

@section('content')
    <div class="section-heading">
        <a href="{{ route('kb') }}">{{ __('Knowledge Base') }}</a> » @if ($article->exists){{ $article->title }}@else{{ __('New Article') }}@endif
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <form class="form-horizontal margin-top" method="POST" action="{{ route('kb.save') }}">
            {{ csrf_field() }}
            <input type="hidden" name="article_id" value="{{ $article->id }}">

            <div class="form-group{{ $errors->has('title') ? ' has-error' : '' }}">
                <label for="title" class="col-sm-2 control-label">{{ __('Title') }}</label>
                <div class="col-sm-8">
                    <input id="title" type="text" class="form-control" name="title" value="{{ old('title', $article->title) }}" maxlength="{{ App\KbArticle::TITLE_MAX_LENGTH }}" required autofocus>
                    @include('partials/field_error', ['field' => 'title'])
                </div>
            </div>

            <div class="form-group">
                <label for="category" class="col-sm-2 control-label">{{ __('Category') }}</label>
                <div class="col-sm-6">
                    <input id="category" type="text" class="form-control input-sized" name="category" value="{{ old('category', $article->exists ? $article->category : request()->category) }}" maxlength="{{ App\KbArticle::CATEGORY_MAX_LENGTH }}" list="kb-categories">
                    <datalist id="kb-categories">
                        @foreach ($categories as $category_option)
                            <option value="{{ $category_option }}">
                        @endforeach
                    </datalist>
                    <div class="form-help">{{ __('Pick one or type a new one.') }}</div>
                </div>
            </div>

            <div class="form-group{{ $errors->has('mailbox_id') ? ' has-error' : '' }}">
                <label for="mailbox_id" class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
                <div class="col-sm-6">
                    <select id="mailbox_id" class="form-control input-sized" name="mailbox_id">
                        @if (Auth::user()->isAdmin())
                            <option value="">{{ __('All Mailboxes') }}</option>
                        @endif
                        @foreach ($mailboxes as $mailbox)
                            <option value="{{ $mailbox->id }}" @if (old('mailbox_id', $article->mailbox_id) == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                        @endforeach
                    </select>
                    @include('partials/field_error', ['field' => 'mailbox_id'])
                </div>
            </div>

            <div class="form-group">
                <label for="kb_body" class="col-sm-2 control-label">{{ __('Article') }}</label>
                <div class="col-sm-9">
                    <textarea id="kb_body" class="form-control" name="body" rows="14">{{ old('body', $article->body) }}</textarea>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-6 col-sm-offset-2">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    <a href="{{ $article->exists ? route('kb.article', ['id' => $article->id]) : route('kb') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>

        @if ($article->exists)
            <form method="POST" action="{{ route('kb.delete', ['id' => $article->id]) }}" class="kb-delete-form" data-confirm="{{ __('Delete this article?') }}">
                {{ csrf_field() }}
                <div class="col-sm-offset-2"><button type="submit" class="btn btn-link text-danger">{{ __('Delete') }}</button></div>
            </form>
        @endif
    </div>
@endsection

@include('partials/editor')

@section('javascript')
    @parent
    kbEditorInit();
@endsection

@extends('layouts.app')

@section('title', $article->exists ? $article->title : __('New Article'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('kb/sidebar_menu', ['category' => (string) $article->category])
@endsection

@section('content')
    <div class="page-content kb">
        @include('partials/flash_messages')

        <form class="settings-form kb-edit" method="POST" action="{{ route('kb.save') }}">
            {{ csrf_field() }}
            <input type="hidden" name="article_id" value="{{ $article->id }}">

            <h2 class="settings-form__heading">@if ($article->exists){{ $article->title }}@else{{ __('New Article') }}@endif</h2>

            <x-fruit::field :label="__('Title')">
                <x-fruit::input id="title" name="title" :value="old('title', $article->title)" :maxlength="App\KbArticle::TITLE_MAX_LENGTH" required autofocus />
            </x-fruit::field>

            <x-fruit::field :label="__('Category')" :description="__('Pick one or type a new one.')">
                <x-fruit::input id="category" name="category" :value="old('category', $article->exists ? $article->category : request()->category)" :maxlength="App\KbArticle::CATEGORY_MAX_LENGTH" list="kb-categories" />
            </x-fruit::field>
            <datalist id="kb-categories">
                @foreach ($categories as $category_option)
                    <option value="{{ $category_option }}">
                @endforeach
            </datalist>

            <x-fruit::field :label="__('Mailbox')">
                <x-fruit::select id="mailbox_id" name="mailbox_id">
                    @if (Auth::user()->isAdmin())
                        <option value="">{{ __('All Mailboxes') }}</option>
                    @endif
                    @foreach ($mailboxes as $mailbox_option)
                        <option value="{{ $mailbox_option->id }}" @selected(old('mailbox_id', $article->mailbox_id) == $mailbox_option->id)>{{ $mailbox_option->name }}</option>
                    @endforeach
                </x-fruit::select>
            </x-fruit::field>

            <x-fruit::field :label="__('Article')">
                <x-editor id="kb_body" name="body" rows="14">{{ old('body', $article->body) }}</x-editor>
            </x-fruit::field>

            <div class="settings-form__actions f-row">
                <x-fruit::button type="submit" variant="primary">{{ __('Save') }}</x-fruit::button>
                <a href="{{ $article->exists ? route('kb.article', ['id' => $article->id]) : route('kb') }}" class="f-button f-button--ghost">{{ __('Cancel') }}</a>
                @if ($article->exists)
                    <x-fruit::button type="submit" variant="danger" form="kb_delete_form" class="kb-edit__delete">{{ __('Delete') }}</x-fruit::button>
                @endif
            </div>
        </form>

        @if ($article->exists)
            <form id="kb_delete_form" method="POST" action="{{ route('kb.delete', ['id' => $article->id]) }}" class="kb-delete-form" data-confirm="{{ __('Delete this article?') }}" x-data x-on:submit.prevent="Tallport.confirm({message: $el.dataset.confirm, confirm: @js(__('Delete')), tone: 'danger'}).then(ok => ok && $el.submit())">
                {{ csrf_field() }}
            </form>
        @endif
    </div>
@endsection

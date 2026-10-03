@extends('layouts.app')

@section('title', $article->title)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('kb/sidebar_menu', ['category' => (string) $article->category])
@endsection

@section('content')
    <div class="section-heading">
        <a href="{{ route('kb') }}">{{ __('Knowledge Base') }}</a>@if ($article->category) » <a href="{{ route('kb', ['category' => $article->category]) }}">{{ $article->category }}</a>@endif
        @if ($article->canBeEditedBy(Auth::user()))<a href="{{ route('kb.edit', ['id' => $article->id]) }}" class="btn btn-bordered margin-left-10">{{ __('Edit') }}</a>@endif
    </div>

    @include('partials/flash_messages')

    <div class="row-container kb-article">
        <h2>{{ $article->title }}</h2>
        <p class="text-help">
            {{ $article->mailbox ? $article->mailbox->name : __('All Mailboxes') }} ·
            {{ __('Updated :date by :person', ['date' => App\User::dateFormat($article->updated_at, 'M j, Y'), 'person' => $article->updatedBy ? $article->updatedBy->getFullName() : '—']) }}
        </p>
        <div class="kb-article-body">{!! \Helper::stripDangerousTags((string) $article->body) !!}</div>
    </div>
@endsection

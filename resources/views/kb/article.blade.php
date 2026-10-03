@extends('layouts.app')

@section('title', $article->title)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('kb/sidebar_menu', ['category' => (string) $article->category, 'kb_action' => $article->canBeEditedBy(Auth::user()) ? ['url' => route('kb.edit', ['id' => $article->id]), 'label' => __('Edit')] : null])
@endsection

@section('content')
    <div class="page-content kb">
        @include('partials/flash_messages')

        <article class="kb-article">
            <h2 class="kb-article__title">{{ $article->title }}</h2>
            <p class="f-muted">
                {{ $article->mailbox ? $article->mailbox->name : __('All Mailboxes') }} ·
                {{ __('Updated :date by :person', ['date' => App\User::dateFormat($article->updated_at, 'M j, Y'), 'person' => $article->updatedBy ? $article->updatedBy->getFullName() : '—']) }}
            </p>
            <div class="f-prose kb-article-body">{!! \Helper::stripDangerousTags((string) $article->body) !!}</div>
        </article>
    </div>
@endsection

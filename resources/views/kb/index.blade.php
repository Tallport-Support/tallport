@extends('layouts.app')

@section('title', __('Knowledge Base'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('kb/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Knowledge Base') }}@if ($category !== '') <small>{{ $category == '-' ? __('No category') : $category }}</small>@endif
        @if ($can_manage)<a href="{{ route('kb.create', $category !== '' && $category != '-' ? ['category' => $category] : []) }}" class="btn btn-bordered margin-left-10">{{ __('New Article') }}</a>@endif
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <form class="kb-search margin-top" method="GET" action="{{ route('kb') }}">
            @if ($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
            <input type="search" name="q" class="form-control" value="{{ $search }}" placeholder="{{ __('Search') }}…" aria-label="{{ __('Search') }}">
        </form>

        @if (!count($articles))
            @if ($search !== '')
                <p class="text-help margin-top">{{ __('No articles found.') }}</p>
            @else
                @include('partials/empty', ['icon' => 'book', 'empty_text' => __('Articles for your team: how things work, answers to common questions. Insert them in replies from the editor; the AI Assistant drafts replies with them.')])
            @endif
        @else
            <ul class="kb-list">
                @php $kb_category = false; @endphp
                @foreach ($articles as $article)
                    @if ($category === '' && $article->category !== $kb_category)
                        @php $kb_category = $article->category; @endphp
                        <li class="kb-list-category">{{ $kb_category ?? __('No category') }}</li>
                    @endif
                    <li class="kb-list-item">
                        <a href="{{ route('kb.article', ['id' => $article->id]) }}">{{ $article->title }}</a>
                        <small class="text-help">{{ $article->mailbox ? $article->mailbox->name : __('All Mailboxes') }} · {{ App\User::dateFormat($article->updated_at, 'M j, Y') }}</small>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection

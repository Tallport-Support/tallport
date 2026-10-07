@extends('layouts.app')

@section('title', __('Knowledge Base'))

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('kb/sidebar_menu', ['kb_action' => $can_manage ? ['url' => route('kb.create', $category !== '' && $category != '-' ? ['category' => $category] : []), 'label' => __('New Article')] : null])
@endsection

@section('content')
    <div class="page-content kb">
        @include('partials/flash_messages')

        <form class="kb-search" method="GET" action="{{ route('kb') }}" role="search">
            @if ($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
            <x-fruit::search name="q" :value="$search" :label="__('Search')" :placeholder="__('Search')" />
        </form>

        @if (!count($articles))
            @if ($search !== '')
                <p class="f-muted">{{ __('No articles found.') }}</p>
            @else
                <x-fruit::empty-state>
                    <x-slot:icon><x-icon.book-open /></x-slot:icon>
                    {{ __('Articles for your team: how things work, answers to common questions. Insert them in replies from the editor; the AI drafts replies with them.') }}
                </x-fruit::empty-state>
            @endif
        @else
            @foreach ($articles->groupBy(fn ($article) => (string) $article->category) as $kb_category => $kb_articles)
                <section class="kb-group">
                    @if ($category === '')
                        <h2 class="f-title-3 kb-group__title">{{ $kb_category !== '' ? $kb_category : __('No category') }}</h2>
                    @endif
                    <x-fruit::item-list>
                        @foreach ($kb_articles as $article)
                            <li>
                                <a href="{{ route('kb.article', ['id' => $article->id]) }}" class="f-item-row">
                                    <span class="f-item-row__top">
                                        <span class="f-item-row__title">{{ $article->title }}</span>
                                        <span class="f-item-row__time">{{ App\User::dateFormat($article->updated_at, 'M j, Y') }}</span>
                                    </span>
                                    <span class="f-item-row__subtitle">{{ $article->mailbox ? $article->mailbox->name : __('All Mailboxes') }}</span>
                                </a>
                            </li>
                        @endforeach
                    </x-fruit::item-list>
                </section>
            @endforeach
        @endif
    </div>
@endsection

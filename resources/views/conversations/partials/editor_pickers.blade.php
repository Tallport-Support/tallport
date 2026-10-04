{{-- The reply editor's pickers: saved replies and knowledge base articles, each
     a searchable list in a FruitUI floating disclosure (public/js/saved_replies.js, kb.js). --}}
@php
    $picker_replies = App\SavedReply::forEditor($mailbox, Auth::user());
    $picker_path = [];
    foreach ($picker_replies as $i => $picker_item) {
        $picker_path = array_slice($picker_path, 0, $picker_item['depth']);
        $picker_replies[$i]['path'] = implode(' › ', $picker_path);
        if ($picker_item['category']) {
            $picker_path[] = $picker_item['name'];
        }
    }
    $picker_articles = App\Http\Controllers\KnowledgeBaseController::forEditor($mailbox->id);
@endphp
<x-fruit::floating-disclosure class="editor-picker saved-replies-picker" x-data="{ q: '' }" x-on:toggle="if ($el.open) { q = ''; $nextTick(() => $el.querySelector('input[type=search]').focus()) }">
    <x-slot:trigger class="f-button f-button--ghost f-button--icon" :aria-label="__('Saved Replies')" :title="__('Saved Replies')"><x-heroicon-o-chat-bubble-bottom-center-text class="f-icon" aria-hidden="true" /></x-slot:trigger>
    <x-fruit::search x-model="q" :label="__('Search')" :placeholder="__('Search').'…'" />
    <ul class="editor-picker__list">
        @foreach ($picker_replies as $picker_item)
            @if ($picker_item['category'])
                <li class="editor-picker__category" style="padding-inline-start: {{ 8 + $picker_item['depth'] * 14 }}px" x-show="!q">{{ $picker_item['name'] }}</li>
            @else
                <li data-search="{{ mb_strtolower($picker_item['path'].' '.$picker_item['name']) }}" x-show="!q || $el.dataset.search.includes(q.toLowerCase())">
                    <button type="button" class="editor-picker__item" style="--picker-depth: {{ $picker_item['depth'] }}" x-on:click="savedReplyInsert({{ $picker_item['id'] }}); $el.closest('details').open = false">
                        <span x-show="q && {{ $picker_item['path'] !== '' ? 'true' : 'false' }}" class="f-muted">{{ $picker_item['path'] }} › </span>{{ $picker_item['name'] }}
                    </button>
                </li>
            @endif
        @endforeach
    </ul>
    @if (!count($picker_replies))
        <p class="f-help editor-picker__empty">{{ __('No saved replies yet.') }}</p>
    @endif
    @if (App\SavedReply::canManage(Auth::user(), $mailbox))
        <form class="f-input-group editor-picker__save" x-data="{ name: '' }" x-on:submit.prevent="savedReplySaveFromReply(name, () => { name = ''; $el.closest('details').open = false })">
            <input type="text" class="f-input" maxlength="75" x-model="name" placeholder="{{ __('Name') }}" aria-label="{{ __('Save as saved reply') }}" required>
            <button type="submit" class="f-button f-button--primary">{{ __('Save') }}</button>
        </form>
    @endif
</x-fruit::floating-disclosure>

@if (count($picker_articles))
    <x-fruit::floating-disclosure class="editor-picker kb-picker" x-data="{ q: '' }" x-on:toggle="if ($el.open) { q = ''; $nextTick(() => $el.querySelector('input[type=search]').focus()) }">
        <x-slot:trigger class="f-button f-button--ghost f-button--icon" :aria-label="__('Knowledge Base')" :title="__('Knowledge Base')"><x-heroicon-o-book-open class="f-icon" aria-hidden="true" /></x-slot:trigger>
        <x-fruit::search x-model="q" :label="__('Search')" :placeholder="__('Search').'…'" />
        <ul class="editor-picker__list">
            @php $picker_category = false; @endphp
            @foreach ($picker_articles as $picker_article)
                @if ($picker_article['category'] !== $picker_category)
                    @php $picker_category = $picker_article['category']; @endphp
                    @if ($picker_category)
                        <li class="editor-picker__category" x-show="!q">{{ $picker_category }}</li>
                    @endif
                @endif
                <li data-search="{{ mb_strtolower($picker_article['category'].' '.$picker_article['title']) }}" x-show="!q || $el.dataset.search.includes(q.toLowerCase())">
                    <button type="button" class="editor-picker__item" x-on:click="kbInsert({{ $picker_article['id'] }}); $el.closest('details').open = false">{{ $picker_article['title'] }}</button>
                </li>
            @endforeach
        </ul>
    </x-fruit::floating-disclosure>
@endif

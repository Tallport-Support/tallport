{{-- Merge conversations into this one (a FruitUI remote dialog; tallportMerge in public/js/conversations.js). --}}
<div class="f-stack modal-form" x-data="tallportMerge({{ $conversation->id }}, {{ \Illuminate\Support\Js::from($prev_conversation_ids) }}, {{ \Illuminate\Support\Js::from(['found' => __('Found #:number'), 'none' => __('No conversation #:number')]) }})">
    <x-fruit::alert tone="warning">
        <p><strong>{{ __("Merging can't be undone.") }}</strong></p>
        <p>{{ __('The messages of the conversations you select move into #:number, and those conversations go to Deleted.', ['number' => $conversation->number]) }}</p>
    </x-fruit::alert>

    <x-fruit::field :label="__('Search Conversation by Number').' (#)'" control-id="merge-conv-number">
        <div class="f-input-group">
            <x-fruit::input id="merge-conv-number" inputmode="numeric" x-model="number" x-on:keydown.enter.prevent="search($refs.search)" />
            <button class="f-button" type="button" x-ref="search" x-on:click="search($el)">{{ __('Search') }}</button>
        </div>
    </x-fruit::field>
    {{-- The search's result, for screen readers. --}}
    <p class="f-sr-only" role="status" x-text="status"></p>

    <ul class="conv-merge-list" x-show="shown.length" x-cloak>
        <template x-for="item in shown" :key="item.id">
            <li>
                <x-fruit::checkbox class="conv-merge-id" x-bind:value="item.id" x-model="selected"><span class="conv-merge-number" x-text="'#' + item.number"></span> <span x-text="item.subject"></span></x-fruit::checkbox>
                <a class="f-button f-button--ghost f-button--small f-button--icon" :href="item.url" target="_blank" :aria-label="{{ \Illuminate\Support\Js::from(__('Open Conversation')) }} + ' #' + item.number" :title="{{ \Illuminate\Support\Js::from(__('Open Conversation')) }} + ' #' + item.number"><x-icon.external-link class="f-icon" aria-hidden="true" /></a>
            </li>
        </template>
    </ul>

    @if (count($prev_conversations))
        <div>
            <span class="f-label">{{ __('Previous Conversations') }}</span>
            <ul class="conv-merge-list">
                @foreach ($prev_conversations as $prev_conversation)
                    {{-- The row ticks the box; the conversation opens from its own button. --}}
                    <li x-show="current !== '{{ $prev_conversation->id }}'">
                        <x-fruit::checkbox class="conv-merge-id" :value="$prev_conversation->id" x-model="selected"><span class="conv-merge-number">#{{ $prev_conversation->number }}</span> {{ $prev_conversation->getSubject() }}</x-fruit::checkbox>
                        <a class="f-button f-button--ghost f-button--small f-button--icon" href="{{ $prev_conversation->url() }}" target="_blank" aria-label="{{ __('Open Conversation') }} #{{ $prev_conversation->number }}" title="{{ __('Open Conversation') }} #{{ $prev_conversation->number }}"><x-icon.external-link class="f-icon" aria-hidden="true" /></a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
{{-- The dialog's footer (FruitUI keeps it in view while the list scrolls). --}}
<footer class="f-dialog__footer" x-data>
    <x-fruit::button x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</x-fruit::button>
    <button class="f-button f-button--primary btn-merge-conv" type="button" x-bind:disabled="!$store.merge.selected.length" x-on:click="$store.merge.merge($el)">{{ __('Merge') }}</button>
</footer>

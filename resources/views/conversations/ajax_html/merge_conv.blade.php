{{-- Merge conversations into this one (a FruitUI remote dialog; tallportMerge in public/js/conversations.js). --}}
<div class="f-stack modal-form" x-data="tallportMerge({{ $conversation->id }})">
    <x-fruit::alert tone="warning">
        <p>{{ __('Selected conversation will be merged into the current conversation behind the popup.') }}</p>
        <p><strong>{{ __("Merged conversations can not be unmerged.") }}</strong></p>
    </x-fruit::alert>

    <x-fruit::field :label="__('Search Conversation by Number').' (#)'" control-id="merge-conv-number">
        <div class="f-input-group">
            <x-fruit::number id="merge-conv-number" x-model="number" x-on:keydown.enter.prevent="search($refs.search)" />
            <button class="f-button" type="button" x-ref="search" x-on:click="search($el)">{{ __('Search') }}</button>
        </div>
    </x-fruit::field>

    <ul class="conv-merge-list" x-show="found.length" x-cloak>
        <template x-for="item in found" :key="item.id">
            <li><label class="f-check"><input type="checkbox" class="conv-merge-id" :value="item.id" x-model="selected"><span><a :href="item.url" target="_blank"><strong x-text="'#' + item.number"></strong> <span x-text="item.subject"></span></a></span></label></li>
        </template>
    </ul>

    @if (count($prev_conversations))
        <div>
            <span class="f-label">{{ __('Previous Conversations') }}</span>
            <ul class="conv-merge-list">
                @foreach ($prev_conversations as $prev_conversation)
                    <li><label class="f-check"><input type="checkbox" class="conv-merge-id" value="{{ $prev_conversation->id }}" x-model="selected"><span><a href="{{ $prev_conversation->url() }}" target="_blank"><strong>#{{ $prev_conversation->number }}</strong> {{ $prev_conversation->getSubject() }}</a></span></label></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="modal-form__actions">
        <button class="f-button f-button--primary btn-merge-conv" type="button" x-bind:disabled="!selected.length" x-on:click="merge($el)">{{ __('Merge') }}</button>
    </div>
</div>

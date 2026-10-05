{{-- The heading's status, viewers, star and subject (App\Livewire\ConversationSubject). --}}
<div class="conv-heading__top @if ($is_following) conv-following @endif">
    <div class="conv-heading__overline">
        <span>{{ $conversation->getStatusName() }} · #{{ $conversation->number }}</span>
        <span class="conv-heading__tools">
            <span id="conv-viewers" wire:ignore>
                @foreach ($viewers as $viewer)
                    <span class="viewer-{{ $viewer['user']->id }} @if ($viewer['replying']) viewer-replying @endif" title="@if ($viewer['replying']){{ __(':user is replying', ['user' => $viewer['user']->getFullName()]) }}@else{{ __(':user is viewing', ['user' => $viewer['user']->getFullName()]) }}@endif">
                        @include('partials/person_photo', ['person' => $viewer['user']])
                    </span>
                @endforeach
            </span>
            <button type="button" class="f-button f-button--ghost f-button--icon f-button--small conv-star" wire:click="star" aria-pressed="{{ $starred ? 'true' : 'false' }}" aria-label="{{ __('Star Conversation') }}" title="@if ($starred){{ __("Unstar Conversation") }}@else{{ __("Star Conversation") }}@endif"><x-icon.star class="f-icon conv-star__off" aria-hidden="true" /><x-icon.star fill="currentColor" class="f-icon conv-star__on" aria-hidden="true" /></button>
        </span>
    </div>
    @unless ($compact)
        <div class="conv-subjtext" x-data="{ editing: false, subject: @js($conversation->getSubject()) }" :class="{ 'conv-subj-editing': editing }">
            <h2 x-on:click="editing = true; $nextTick(() => $refs.subject.focus())">{{ $conversation->getSubject() }}</h2>
            <div class="f-input-group conv-subj-editor">
                <input type="text" id="conv-subj-value" class="f-input" x-ref="subject" x-model="subject" x-on:keydown.enter.prevent="$refs.save.click()" x-on:keydown.escape="editing = false" aria-label="{{ __('Subject') }}" />
                <button class="f-button f-button--primary" type="button" x-ref="save" x-on:click="Tallport.busy($el, true); $wire.saveSubject(subject).then(() => { Tallport.busy($el, false); editing = false })" aria-label="{{ __('Save') }}"><x-icon.check class="f-icon" aria-hidden="true" /></button>
            </div>
        </div>
    @endunless
</div>

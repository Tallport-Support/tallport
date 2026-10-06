{{-- A status's dot (Conversation::STATUS_TONES); $status null: a hollow ring (statuses differ). Not read out: the status's name is beside it or in its button's label. --}}
@if ($status === null)
    <span class="conv-status-dot conv-status-dot--mixed" aria-hidden="true"></span>
@else
    <span class="f-badge f-badge--{{ App\Conversation::STATUS_TONES[$status] ?? 'neutral' }} conv-status-dot" aria-hidden="true"></span>
@endif

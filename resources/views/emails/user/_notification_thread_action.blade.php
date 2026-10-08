@if ($thread->type == App\Thread::TYPE_NOTE)
    <span>
        {!! __h(':person added a note', ['person' => '<strong>'.htmlspecialchars($thread->getCreatedBy()->getFullName(true)).'</strong>']) !!}
    </span>
@else
    <span>
        @if ($thread->isForwarded())
            @php $trans_text = __(':person forwarded a conversation :forward_parent_conversation_number') @endphp
        @elseif ($loop->last)
            @php $trans_text = __(':person started the conversation') @endphp
        @else
            @php $trans_text = __(':person replied') @endphp
        @endif
        @php
            $trans_params = ['person' => '<strong>'.htmlspecialchars($thread->getCreatedBy()->getFullName(true)).'</strong>'];
            // Highlight sender when From is different from Reply-To.
            if ($thread->isCustomerMessage() &&
                ($from_header = $thread->getFromIfDifferentFromReplyTo($customer ?? null))
            ) {
                $trans_params['person'] .= ' <span style="color:#b37100;">&lt;'.$from_header.'&gt;</span>';
            }
            if ($thread->isForwarded()) {
                $trans_params['forward_parent_conversation_number'] = '<a href="'.route('conversations.view', ['id' => $thread->getMetaFw(App\Thread::META_FORWARD_PARENT_CONVERSATION_ID)]).'#thread-'.htmlspecialchars($thread->getMetaFw(App\Thread::META_FORWARD_PARENT_THREAD_ID)).'">#'.htmlspecialchars($thread->getMetaFw(App\Thread::META_FORWARD_PARENT_CONVERSATION_NUMBER)).'</a>';
            }
        @endphp
        {!! __h($trans_text, $trans_params) !!}
    </span>
@endif
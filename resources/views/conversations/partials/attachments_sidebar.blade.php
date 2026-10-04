{{-- Every attachment of the conversation, newest message first. --}}
@php
    $sidebar_attachments = $conversation->has_attachments
        ? App\Attachment::whereIn('thread_id', $conversation->threads()->where('has_attachments', true)->whereIn('state', [App\Thread::STATE_PUBLISHED])->select('id'))
            ->where('embedded', false)
            ->join('threads', 'threads.id', '=', 'attachments.thread_id')
            ->orderBy('threads.created_at', 'desc')->orderBy('attachments.id')
            ->select('attachments.*')
            ->get()
        : collect();
@endphp
@if (count($sidebar_attachments))
    <section class="conv-sidebar-block attachments-block inspector-section">
        <h3>{{ __('Attachments') }} <span class="f-muted">{{ count($sidebar_attachments) }}</span></h3>
        <ul class="sidebar-block-list attachments-list">
            @foreach ($sidebar_attachments as $attachment)
                <li data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}" @if (App\Http\Controllers\AttachmentsController::isEmail($attachment)) data-email-url="{{ route('attachments.email', ['id' => $attachment->id]) }}" @endif>
                    <a href="{{ $attachment->url() }}" target="_blank" class="attachment-link"><x-icon.paperclip class="f-icon" aria-hidden="true" /><span>{{ $attachment->file_name }} <span class="f-muted">({{ $attachment->getSizeName() }})</span></span></a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

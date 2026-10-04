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
    <div class="conv-sidebar-block attachments-block">
        <div class="panel-group accordion accordion-empty">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title">
                        <a data-toggle="collapse" href=".collapse-attachments">{{ __('Attachments') }} <x-heroicon-o-chevron-down class="f-icon" aria-hidden="true" /></a>
                    </h4>
                </div>
                <div class="collapse-attachments panel-collapse collapse in">
                    <div class="panel-body">
                        <div class="sidebar-block-header2"><strong>{{ __('Attachments') }}</strong> ({{ count($sidebar_attachments) }})</div>
                        <ul class="sidebar-block-list attachments-list">
                            @foreach ($sidebar_attachments as $attachment)
                                <li data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}" @if (App\Http\Controllers\AttachmentsController::isEmail($attachment)) data-email-url="{{ route('attachments.email', ['id' => $attachment->id]) }}" @endif>
                                    <a href="{{ $attachment->url() }}" target="_blank" class="attachment-link help-link"><i class="glyphicon glyphicon-paperclip"></i>{{ $attachment->file_name }} <span class="text-help">({{ $attachment->getSizeName() }})</span></a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

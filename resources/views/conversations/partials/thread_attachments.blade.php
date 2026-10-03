@if ($thread->has_attachments)
    <div class="thread-attachments">
        <i class="glyphicon glyphicon-paperclip"></i>
        <ul>
            @foreach ($thread->attachments as $attachment)
                <li data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}" @if (App\Http\Controllers\AttachmentsController::isEmail($attachment)) data-email-url="{{ route('attachments.email', ['id' => $attachment->id]) }}" @endif>
                    <a href="{{ $attachment->url() }}" class="attachment-link break-words" target="_blank">{{ $attachment->file_name }}</a>
                    <span class="text-help">({{ $attachment->getSizeName() }})</span>
                    <a href="{{ $attachment->url() }}" download><i class="glyphicon glyphicon-download-alt small"></i></a>
                    @if (Auth::user() && App\Http\Controllers\AttachmentsController::canDelete(Auth::user()))
                        <a href="#" class="attachment-delete" data-attachment-id="{{ $attachment->id }}" data-confirm="{{ __('Delete :file_name?', ['file_name' => $attachment->file_name]) }}" title="{{ __('Delete') }}"><i class="glyphicon glyphicon-trash small"></i></a>
                    @endif
                    @action('thread.attachment_append', $attachment, $thread, $conversation, $mailbox)
                </li>
            @endforeach
            @if (count($thread->attachments) > 1)
                <li><a href="{{ route('attachments.download_all', ['thread_id' => $thread->id]) }}" class="break-words">{{ __('Download all') }}</a></li>
            @endif
            @action('thread.attachments_list_append', $thread, $conversation, $mailbox)
        </ul>
    </div>
@endif

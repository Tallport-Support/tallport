{{-- A message's files, as FruitUI attachments with Delete among their actions: they open in the viewer (public/js/attachments.js). --}}
@foreach ($thread->attachments as $attachment)
    <span class="conv-attachment" data-attachment-id="{{ $attachment->id }}" data-mime="{{ $attachment->mime_type }}" data-file-name="{{ $attachment->file_name }}" @if (App\Http\Controllers\AttachmentsController::isEmail($attachment)) data-email-url="{{ route('attachments.email', ['id' => $attachment->id]) }}" @endif>
        <x-fruit::attachment :href="$attachment->url()" class="attachment-link" target="_blank">
            <x-slot:leading><x-icon.paperclip class="f-icon" aria-hidden="true" /></x-slot:leading>
            {{ $attachment->file_name }}
            <x-slot:detail>{{ $attachment->getSizeName() }}</x-slot:detail>
            @if (Auth::user() && App\Http\Controllers\AttachmentsController::canDelete(Auth::user()))
                <x-slot:actions><x-fruit::button variant="ghost" class="f-button--icon attachment-delete" :data-attachment-id="$attachment->id" :data-confirm="__('Delete :file_name?', ['file_name' => $attachment->file_name])" :aria-label="__('Delete').': '.$attachment->file_name" :title="__('Delete')"><x-icon.trash-2 class="f-icon" aria-hidden="true" /></x-fruit::button></x-slot:actions>
            @endif
        </x-fruit::attachment>
        @action('thread.attachment_append', $attachment, $thread, $conversation, $mailbox)
    </span>
@endforeach
@if (count($thread->attachments) > 1)
    <x-fruit::attachment :href="route('attachments.download_all', ['thread_id' => $thread->id])" download>
        <x-slot:leading><x-icon.download class="f-icon" aria-hidden="true" /></x-slot:leading>
        {{ __('Download all') }}
    </x-fruit::attachment>
@endif
@action('thread.attachments_list_append', $thread, $conversation, $mailbox)

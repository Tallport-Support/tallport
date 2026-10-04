{{-- The list pane's toolbar: the folder, its mailbox and how many conversations it holds. --}}
<div class="app-list-title">
    <h1>{{ $folder->getTypeName() }}</h1>
    <p>@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}@if (method_exists($conversations, 'total')) · {{ __(':count conversations', ['count' => $conversations->total()]) }}@endif</p>
</div>
<span class="f-toolbar__spacer"></span>
@if (($folder->type == App\Folder::TYPE_DELETED || $folder->type == App\Folder::TYPE_SPAM) && $folder->total_count)
    <x-fruit::button variant="danger" size="small" class="mailbox-empty-folder" x-data="tallportEmptyFolder({{ $folder->id }}, {{ $mailbox->id }}, {{ \Illuminate\Support\Js::from(__('Delete the conversations?')) }}, {{ \Illuminate\Support\Js::from(__('Delete')) }})" x-on:click="empty($el)">@if ($folder->type == App\Folder::TYPE_DELETED){{ __('Empty Trash') }}@else{{ __('Delete All') }}@endif</x-fruit::button>
@endif

{{-- The list pane's toolbar: the folder, its mailbox and how many conversations it holds. --}}
<div class="app-list-title">
    <h1>{{ $folder->getTypeName() }}</h1>
    <p>@include('mailboxes/partials/mute_icon', ['mailbox' => $mailbox]){{ $mailbox->name }}@if (method_exists($conversations, 'total')) · {{ __(':count conversations', ['count' => $conversations->total()]) }}@endif</p>
</div>
<span class="f-toolbar__spacer"></span>
@if (($folder->type == App\Folder::TYPE_DELETED || $folder->type == App\Folder::TYPE_SPAM) && $folder->total_count)
    <a href="#" class="f-button f-button--small f-button--danger mailbox-empty-folder">@if ($folder->type == App\Folder::TYPE_DELETED){{ __('Empty Trash') }}@else{{ __('Delete All') }}@endif</a>
@endif

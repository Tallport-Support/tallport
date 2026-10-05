{{-- A mailbox's settings pages (in the settings sidebar, partials/app_sidebar_settings). Modules add theirs with mailboxes.settings.menu. --}}
@if (Auth::user()->can('update', $mailbox))
    @if (Auth::user()->isAdmin() || Auth::user()->hasManageMailboxPermission($mailbox->id, App\Mailbox::ACCESS_PERM_EDIT) || Auth::user()->hasManageMailboxPermission($mailbox->id, App\Mailbox::ACCESS_PERM_SIGNATURE))
        <a wire:navigate href="{{ route('mailboxes.update', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.update') aria-current="page" @endif>{{ __('Edit Mailbox') }}</a>
    @endif
    @if (Auth::user()->isAdmin())
        <a wire:navigate href="{{ route('mailboxes.connection', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.connection' || Route::currentRouteName() == 'mailboxes.connection.incoming') aria-current="page" @endif>{{ __('Connection Settings') }}</a>
    @endif
    @if (Auth::user()->isAdmin() || Auth::user()->hasManageMailboxPermission($mailbox->id, App\Mailbox::ACCESS_PERM_PERMISSIONS))
        <a wire:navigate href="{{ route('mailboxes.permissions', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.permissions') aria-current="page" @endif>{{ __('Permissions') }}</a>
    @endif
    @if (Auth::user()->isAdmin() || Auth::user()->hasManageMailboxPermission($mailbox->id, App\Mailbox::ACCESS_PERM_AUTO_REPLIES))
        <a wire:navigate href="{{ route('mailboxes.auto_reply', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.auto_reply') aria-current="page" @endif>{{ __('Auto Reply') }}</a>
    @endif
    @if (Auth::user()->isAdmin())
        <a wire:navigate href="{{ route('mailboxes.telegram', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.telegram') aria-current="page" @endif>{{ __('Telegram') }}</a>
    @endif
    <a wire:navigate href="{{ route('mailboxes.nostr', ['id'=>$mailbox->id]) }}" @if (Route::currentRouteName() == 'mailboxes.nostr') aria-current="page" @endif>Nostr</a>
@endif
@if (App\Workflow::canEdit(Auth::user(), $mailbox))
    <a wire:navigate href="{{ route('mailboxes.workflows', ['mailbox_id' => $mailbox->id]) }}" @if (Str::startsWith(Route::currentRouteName(), 'mailboxes.workflows')) aria-current="page" @endif>{{ __('Workflows') }}</a>
@endif
@if (App\SavedReply::canManage(Auth::user(), $mailbox))
    <a wire:navigate href="{{ route('mailboxes.saved_replies', ['id'=>$mailbox->id]) }}" @if (Str::startsWith(Route::currentRouteName(), 'mailboxes.saved_replies')) aria-current="page" @endif>{{ __('Saved Replies') }}</a>
@endif
@action('mailboxes.settings.menu', $mailbox)

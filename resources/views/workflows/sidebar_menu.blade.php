{{-- Workflows of all mailboxes and of each mailbox (admins). --}}
<div class="sidebar-title">
    {{ __('Workflows') }}
</div>
<ul class="sidebar-menu">
    <li @if (!$mailbox)class="active"@endif><a href="{{ route('workflows') }}"><i class="glyphicon glyphicon-inbox"></i> {{ __('All Mailboxes') }}</a></li>
    @foreach (Auth::user()->mailboxesCanView() as $sidebar_mailbox)
        <li @if ($mailbox && $mailbox->id == $sidebar_mailbox->id)class="active"@endif><a href="{{ route('mailboxes.workflows', ['mailbox_id' => $sidebar_mailbox->id]) }}"><i class="glyphicon glyphicon-envelope"></i> {{ $sidebar_mailbox->name }}</a></li>
    @endforeach
</ul>

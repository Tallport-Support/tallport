@extends('emails/user/layouts/system')

@section('content')
	<p><strong>{{ __('Congratulations! Your mailbox can send emails!') }}</strong></p>
	<p>
		{!! __h('This is a test mail sent by :app_name. It means that outgoing email settings of your :mailbox mailbox are fine.', ['app_name' => '<strong>'.htmlspecialchars(\Config::get('app.name')).'</strong>', 'mailbox' => '<a href="'.route('mailboxes.connection', ['id' => $mailbox->id]).'" target="_blank">'.htmlspecialchars($mailbox->name).'</a>']) !!}
	</p>
@endsection

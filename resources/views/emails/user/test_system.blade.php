@extends('emails/user/layouts/system')

@section('content')
	<p><strong>{{ __('Congratulations! Your application can send emails!') }}</strong></p>
	<p>
		{!! __h('This is a test system mail sent by :app_name. It means that mail settings are fine.', ['app_name' => '<strong>'.htmlspecialchars(\Config::get('app.name')).'</strong>']) !!}
	</p>
@endsection

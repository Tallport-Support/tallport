@extends('emails/user/layouts/system')

@section('content')
	<p>
	  {!! __h('Hi :user, an account has been created for you at :app_url', ['user' => '<strong>'.htmlspecialchars($user->getFullName()).'</strong>', 'app_url' => '<a href="'.htmlspecialchars(\Config::get('app.url')).'" target="_blank">'.htmlspecialchars(parse_url(\Config::get('app.url'), PHP_URL_HOST)).'</a>']) !!}
	</p>
	<p>
		<a href="{{ $user->urlSetup() }}" target="_blank"><strong>{{ __('Create a Password') }}</strong></a>
	</p>
	<p><strong>{{ __('Welcome to the team!') }}</strong></p>
	<p>
		{{ __('Someone on your team created an account for you.') }}
	</p>
@endsection

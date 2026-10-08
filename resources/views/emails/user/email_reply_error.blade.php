@extends('emails/user/layouts/system')

@section('content')
	<p><strong>{{ __("Your email update couldn't be processed") }}</strong></p>
	<p style="border-left: 3px solid #e52f28; padding: 0 0 0 10px;">
		{{ $text ?: __("If you are trying to update a conversation, remember you must respond from the same email address that's on your account. To send your update, please try again and send from your account email address (the email you login with).")  }}
	</p>
@endsection

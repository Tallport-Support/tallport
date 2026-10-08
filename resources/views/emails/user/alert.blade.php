@extends('emails/user/layouts/system')

@section('content')

	<p><strong>
		@if (!empty($title))
			{{ $title }}
		@else
			{{ __('System Alert') }}
		@endif
		 - {{ \Helper::getDomain() }}
	</strong></p>

	<p>
		{!! $text !!}
	</p>

	<p style="color:#999999; font-size:smaller;">
		{!! __('You can adjust alert settings :%a_begin%here:%a_end%', ['%a_begin%' => '<a href="'.route('settings', ['section' => 'alerts']).'">', '%a_end%' => '<a/>']) !!}
	</p>
@endsection

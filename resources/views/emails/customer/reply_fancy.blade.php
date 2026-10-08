<html lang="{{ app()->getLocale() }}" @if (\Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="initial-scale=1.0">
    {{-- No fonts, sizes or colours: the reader's mail app shows the reply in its own, like other emails. --}}
    <style>
        p { margin:0 0 1em 0; }
        pre { font-family: Menlo, Monaco, monospace; }
        img { max-width:100%; }
        {!! safe_raw_html(\Eventy::filter('reply_email.css', '')) !!}
    </style>
</head>
<body>@php $reply_separator = \MailHelper::getHashedReplySeparator($headers['Message-ID']); @endphp
	<div id="{{ $reply_separator }}" class="{{ $reply_separator }}" data-fs="{{ $reply_separator }}">{!! safe_raw_html(\Eventy::filter('reply_email.header', '')) !!}
	    @php
	    	$is_forwarded = !empty($threads[0]) ? $threads[0]->isForwarded() : false;
	    	$is_rtl = \Helper::isLocaleRtl();
	    	$rtl_style = 'text-align: right; direction: rtl; unicode-bidi: plaintext;';
	    	$signature_mailbox = $mailbox;
	    	$locale = app()->getLocale() == 'kz' ? 'kk' : app()->getLocale();
	    @endphp
		@foreach ($threads as $thread)
			{{-- The earlier messages are quoted as Gmail does: "On …, … wrote:" above each. --}}
			@if ($loop->index == 1)<br><!-- originalMessage --><div class="gmail_quote">@endif
			@if (!$loop->first)
				@php
					if (!empty($mailbox_change_history[$thread->id])) {
						$new_mailbox = App\Mailbox::find($mailbox_change_history[$thread->id]);
						if ($new_mailbox) {
							$signature_mailbox = $new_mailbox;
						}
					}
				@endphp
				<div class="gmail_attr" @if ($is_rtl) style="{{ $rtl_style }}" @endif>{{ __('On :date, :person wrote:', ['date' => $thread->created_at->copy()->locale($locale)->isoFormat('ll LT'), 'person' => $thread->getFromName($mailbox).($is_forwarded && $thread->from ? ' <'.$thread->from.'>' : '')]) }}<br></div>
				<blockquote class="gmail_quote" style="margin:0 0 0 0.8ex; border-left:1px solid #cccccc; padding-left:1ex; @if ($is_rtl) margin:0 0.8ex 0 0; border-left:0; border-right:1px solid #cccccc; padding-left:0; padding-right:1ex; @endif">
			@endif
			<div @if ($is_rtl) style="{{ $rtl_style }}" @endif>
				@if ($thread->source_via == App\Thread::PERSON_USER && $mailbox->before_reply && $loop->first)
					<span style="color:#b5b5b5">{{ $mailbox->before_reply }}</span><br><br>
				@endif
				{!! $thread->getCleanBody('', true) !!}

				@action('reply_email.before_signature', $thread, $loop, $threads, $conversation, $mailbox, $threads_count)
				@if ($thread->source_via == App\Thread::PERSON_USER && \Eventy::filter('reply_email.include_signature', true, $thread))
					<br>{!! $conversation->getSignatureProcessed(['thread' => $thread], true, $signature_mailbox) !!}
				@endif
				@action('reply_email.after_signature', $thread, $loop, $threads, $conversation, $mailbox, $threads_count)
			</div>
			@if (!$loop->first)</blockquote>@if (!$loop->last)<br>@endif @endif
			@if ($loop->last && !$loop->first)</div>@endif
		@endforeach
		@if (\App\Option::get('email_branding'))
			<br><div style="font-size:smaller; color:#999999; @if ($is_rtl) {{ $rtl_style }} @endif">
				{!! __h('Support powered by :app_name — Free open source help desk & shared mailbox', ['app_name' => \Helper::productCreditHtml()]) !!}
			</div>
		@endif
		<div style="height:0; font-size:0px; line-height:0px; color:#ffffff;">	                    	
			@if (\App\Option::get('open_tracking'))
				@php
					$first_thread = $threads->first();
				@endphp
				<img src="{{ route('open_tracking.set_read', ['conversation_id' => $first_thread->conversation_id, 'thread_id' => $first_thread->id, 'hash' => App\Thread::getOpenTrackingHash($first_thread, $conversation, $mailbox), 'otr' => '1']) }}" alt="" />
			@endif
			<span style="font-size: 0px; line-height: 0px; color:#ffffff !important;">{{-- Extra symbols not to show message marker in Gmail snippet preview --}} &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj; &zwnj;{{-- In addition to Message-ID header to detect relies --}}{{ \MailHelper::getMessageMarker($headers['Message-ID']) }}</span>
		</div>{!! safe_raw_html(\Eventy::filter('reply_email.footer', '')) !!}
	</div>
</body>
</html>
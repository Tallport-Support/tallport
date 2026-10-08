@if ($mailbox->before_reply){{ $mailbox->getReplySeparator() }}

@endif@php $is_forwarded = !empty($threads[0]) ? $threads[0]->isForwarded() : false; $signature_mailbox = $mailbox; @endphp
@php $locale = app()->getLocale() == 'kz' ? 'kk' : app()->getLocale(); @endphp
@foreach ($threads as $thread)
@php
	if ($loop->index > 0 && !empty($mailbox_change_history[$thread->id])) {
		$new_mailbox = App\Mailbox::find($mailbox_change_history[$thread->id]);
		if ($new_mailbox) {
			$signature_mailbox = $new_mailbox;
		}
	}
	// Html2Text\Html2Text::convert($thread->body) - this was causing "AttValue: " expected in Entity" error sometimes
	$text = \Helper::htmlToText($thread->body, true);
	if ($thread->source_via == App\Thread::PERSON_USER && \Eventy::filter('reply_email.include_signature', true, $thread)) {
		$text .= "\n".\Helper::htmlToText($conversation->getSignatureProcessed(['thread' => $thread], false, $signature_mailbox));
	}
	// The earlier messages are quoted as Gmail does: "On …, … wrote:" and "> " before each line.
	if (!$loop->first) {
		$text = preg_replace('/^/m', '> ', rtrim($text));
	}
@endphp
@if (!$loop->first)

{!! __('On :date, :person wrote:', ['date' => $thread->created_at->copy()->locale($locale)->isoFormat('ll LT'), 'person' => $thread->getFromName($mailbox).($is_forwarded && $thread->from ? ' <'.$thread->from.'>' : '')]) !!}
@endif
{!! $text !!}
@endforeach
@if (\App\Option::get('email_branding'))
-----------------------------------------------------------
{!! __('Support powered by :app_name — Free open source help desk & shared mailbox', ['app_name' => \Config::get('app.name')]) !!}
@endif
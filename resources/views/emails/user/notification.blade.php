<html lang="{{ app()->getLocale() }}" @if (\Helper::isLocaleRtl()) dir="rtl" @endif>
<head>
	<meta content="text/html; charset=utf-8" http-equiv="Content-Type">
	<meta name="viewport" content="initial-scale=1.0">
	{{-- No fonts, sizes or colours for the text: the reader's mail app shows it in its own, like other emails. --}}
	<style>
		p { margin:0 0 1em 0; }
		pre { font-family: Menlo, Monaco, monospace; }
		img { max-width:100%; }
	</style>
</head>
<body>
	@php
		$is_rtl = \Helper::isLocaleRtl();
		$rtl_style = $is_rtl ? 'text-align: right; direction: rtl; unicode-bidi: plaintext;' : '';
		$muted_style = 'color:#999999; font-size:smaller;';
	@endphp
	<div id="{{ \MailHelper::REPLY_SEPARATOR_NOTIFICATION }}" class="{{ \MailHelper::REPLY_SEPARATOR_NOTIFICATION }}" data-fs="{{ \MailHelper::REPLY_SEPARATOR_NOTIFICATION }}" style="{{ $rtl_style }}">
		<div style="{{ $muted_style }}">{{ $mailbox->getReplySeparator() }}</div>
		<br>

		{{-- What happened, the conversation and who a reply goes to. --}}
		<div>
			@if (count($threads) == 1)
				{{ __('Received a new conversation') }}
			@else
				@if ($thread->action_type == App\Thread::ACTION_TYPE_STATUS_CHANGED)
					{!! __h(":person marked as :status conversation", ['person' => '<strong>'.htmlspecialchars($thread->getCreatedBy()->getFullName(true)).'</strong>', 'status' => htmlspecialchars($thread->getStatusName())]) !!}
				@elseif ($thread->action_type == App\Thread::ACTION_TYPE_USER_CHANGED)
					<strong>@include('emails/user/thread_by')</strong>
					{{ __("assigned to :person conversation", ['person' => $thread->getAssigneeName(false, $user)]) }}
				@elseif ($thread->type == App\Thread::TYPE_NOTE)
					{!! __h(":person added a note to conversation", ['person' => '<strong>'.htmlspecialchars($thread->getCreatedBy()->getFullName(true)).'</strong>']) !!}
				@else
					{!! __h(":person replied to conversation", ['person' => '<strong>'.htmlspecialchars($thread->getCreatedBy()->getFullName(true)).'</strong>']) !!}
				@endif
			@endif
			<a href="{{ \Eventy::filter('email_notification.conv_url', $conversation->url(), $user) }}">#{{ $conversation->number }}</a>
		</div>
		<div><strong>{{ $conversation->subject ?? '' }}</strong></div>
		<div style="{{ $muted_style }}">
			{{ $conversation->getStatusName() }}@if ($conversation->user_id && $conversation->user) · {{ __('Assigned to') }} {{ $conversation->user->getFullName() }}@endif · {{ $mailbox->name }}
			<br>{{ __('Replying to this notification will email :name', ['name' => ($customer ? $customer->getFirstName(true) : '')]) }} (<a href="mailto:{{ $conversation->customer_email }}">{{ $conversation->customer_email ?? '' }}</a>)
			@if ($conversation->getCcArray())
				<br>CC: {{ implode(', ', $conversation->getCcArray()) }}
			@endif
		</div>

		@foreach ($threads as $thread)
			<hr style="border:0; border-top:1px solid #cccccc; margin:1.25em 0;">
			@if ($thread->type == App\Thread::TYPE_LINEITEM)
				{{-- Line item --}}
				<div style="{{ $muted_style }}">
					{!! safe_raw_html($thread->getActionText('', true, false, $user, htmlspecialchars(view('emails/user/thread_by', ['thread' => $thread, 'user' => $user])->render()))) !!}
					· {{ App\User::dateFormat($thread->created_at, 'M j, H:i', $user) }}
				</div>
			@else
				{{-- Reply, note or customer message; notes are marked by a bar beside them. --}}
				<div @if ($thread->type == App\Thread::TYPE_NOTE) style="border-{{ $is_rtl ? 'right' : 'left' }}:3px solid #e6b216; padding-{{ $is_rtl ? 'right' : 'left' }}:1ex;" @endif>
					<div>
						@include('emails.user._notification_thread_action', ['thread' => $thread])
						<span style="{{ $muted_style }}">· {{ App\User::dateFormat($thread->created_at, 'M j, H:i', $user) }}</span>
					</div>
					<br>
					@if ($thread->isForward())
						<p style="border-{{ $is_rtl ? 'right' : 'left' }}:3px solid #e6b216; padding-{{ $is_rtl ? 'right' : 'left' }}:1ex;">
							{!! __h(':person forwarded this conversation. Forwarded conversation: :forward_child_conversation_number', [
							'person' => htmlspecialchars(ucfirst($thread->getForwardByFullName())),
							'forward_child_conversation_number' => '<a href="'.route('conversations.view', ['id' => $thread->getMetaFw(App\Thread::META_FORWARD_CHILD_CONVERSATION_ID)]).'">#'.htmlspecialchars($thread->getMetaFw(App\Thread::META_FORWARD_CHILD_CONVERSATION_NUMBER)).'</a>'
							]) !!}
						</p>
					@endif
					@action('email_notification.before_body', $thread, $user, $conversation)
					<div>
						{!! safe_raw_html($thread->getCleanBody() ?? '') !!}
					</div>

					@if ($thread->has_attachments)
						<br>
						<div>
							<strong>{{ __('Attached:') }}</strong>
							@foreach ($thread->attachments as $attachment)
								<a href="{{ $attachment->url() }}">{{ $attachment->file_name ?? '' }}</a> <span style="{{ $muted_style }}">({{ $attachment->getSizeName() }})</span>@if (!$loop->last), &nbsp;@endif
							@endforeach
						</div>
					@endif
				</div>
			@endif
		@endforeach

		<hr style="border:0; border-top:1px solid #cccccc; margin:1.25em 0;">
		<div style="{{ $muted_style }}">
			<a href="{{ \Eventy::filter('email_notification.settings_url', route('users.notifications', ['id' => $user->id]), $user) }}" style="color:#999999;">{{ __('Notification Settings') }}</a>{{ \Eventy::action('email_notification.footer_links', $mailbox, $conversation, $threads) }} - <a href="{{ \Eventy::filter('email_notification.mailbox_url', $mailbox->url(), $user) }}" style="color:#999999;">{{ $mailbox->name }}</a>
		</div>

		{{-- Addition to Message-ID header to detect relies --}}
		<div style="font-size: 0px; line-height: 0px; color:#ffffff !important;">{{ \MailHelper::getMessageMarker($headers['Message-ID']) }}</div>
	</div>
	<div itemscope itemtype="http://schema.org/EmailMessage">
		<div itemprop="potentialAction" itemscope itemtype="http://schema.org/ViewAction">
			<link itemprop="target" href="{{ $conversation->url() }}"/>
			<meta itemprop="name" content="{{ __('Open Conversation') }}"/>
		</div>
		<meta itemprop="description" content="{{ __('Open this conversation in :app_name', ['app_name' => config('app.name')]) }}"/>
	</div>
</body>
</html>

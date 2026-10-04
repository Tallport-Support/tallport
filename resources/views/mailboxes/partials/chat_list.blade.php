@php
	$chats = \App\Conversation::getChats($mailbox->id, $offset ?? 0);
@endphp
@foreach ($chats as $chat_i => $chat)
	@if ($chat_i < App\Conversation::CHATS_LIST_SIZE)
	    <li class="chat-item @if ($chat->isActive()) new @endif" data-chat_id="{{ $chat->id }}">
	        <a href="{{ $chat->url(null, null, ['chat_mode' => 1]) }}" class="f-item-row" @if (isset($conversation) && $chat->id == $conversation->id) aria-current="true" @endif>
	            <span class="f-item-row__top">
	                <strong class="f-item-row__title">{{ $chat->customer->getFullName(true) }}</strong>
	                <span class="f-item-row__time" title="{{ App\User::dateFormat($chat->last_reply_at) }}">{{ \App\User::dateDiffForHumans($chat->last_reply_at) }}</span>
	            </span>
	            <span class="f-item-row__preview">{{ $chat->preview }}</span>
	            <span class="f-item-row__meta"><span class="f-badge">{{ $chat->getChannelName() }}</span>@if (!$chat->user_id) <span class="f-badge f-badge--success">{{ __('Unassigned') }}</span>@endif</span>
	        </a>
	    </li>
	@else
		<li class="chat-list__more">
	        <a href="#" class="f-button f-button--ghost chats-load-more" data-loading-text="···" aria-label="{{ __('Load more') }}"><x-heroicon-o-chevron-down class="f-icon" aria-hidden="true" /></a>
	    </li>
	@endif
@endforeach

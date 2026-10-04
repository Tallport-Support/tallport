@php
    $thread_date_title = App\User::dateFormat($thread->created_at);
    $thread_date = \Helper::isPrint() ? $thread_date_title : App\User::dateDiffForHumans($thread->created_at);
@endphp
@if ($thread->type == App\Thread::TYPE_LINEITEM)
    {{-- An event between messages (assignments, status changes, merges). --}}
    <x-fruit::message-event class="thread thread-type-lineitem thread-state-{{ $thread->getStateName() }}" id="thread-{{ $thread->id }}" data-thread_id="{{ $thread->id }}" :datetime="$thread->created_at->toIso8601String()">
        {!! safe_raw_html($thread->getActionText('', true, false, null, view('conversations/thread_by', ['thread' => $thread])->render())) !!}
        @action('thread.after_header', $thread, $loop, $threads, $conversation, $mailbox)
        <x-slot:time><a href="#thread-{{ $thread->id }}" class="thread-date" title="{{ $thread_date_title }}">{{ $thread_date }}</a></x-slot:time>
    </x-fruit::message-event>
@else
    @php
        $thread_person = $thread->getPerson(true);
        $thread_is_draft = $thread->type == App\Thread::TYPE_MESSAGE && $thread->state == App\Thread::STATE_DRAFT;
        $thread_initials = $thread_person ? mb_strtoupper(mb_substr((string) $thread_person->first_name, 0, 1).mb_substr((string) $thread_person->last_name, 0, 1)) : '';
        if ($thread_initials === '' && $thread_person && method_exists($thread_person, 'getMainEmail')) {
            $thread_initials = mb_strtoupper(mb_substr((string) $thread_person->getMainEmail(), 0, 1));
        }
        $send_status_data = $thread_is_draft ? null : $thread->getSendStatusData();
    @endphp
    <x-fruit::message layout="stacked" :variant="$thread->isNote() ? 'note' : 'default'" class="thread thread-type-{{ $thread_is_draft ? 'draft' : $thread->getTypeName() }}" id="thread-{{ $thread->id }}" data-thread_id="{{ $thread->id }}" :datetime="$thread->created_at->toIso8601String()">
        <x-slot:avatar>
            @if ($thread_person && $thread_person->photo_url)
                <x-fruit::avatar :src="$thread_person->getPhotoUrl()" />
            @else
                <x-fruit::avatar>{{ $thread_initials }}</x-fruit::avatar>
            @endif
        </x-slot:avatar>
        <x-slot:author>
            @if ($thread->type == App\Thread::TYPE_CUSTOMER)
                @if ($thread->customer_cached)
                    @if (\Helper::isPrint()){{ $thread->customer_cached->getFullName(true) }}@else<a href="{{ $thread->customer_cached->url() }}">{{ $thread->customer_cached->getFullName(true) }}</a>@endif
                @endif
            @else
                @if (\Helper::isPrint()){{ $thread->created_by_user_cached->getFullName() }}@else @include('conversations/thread_by', ['as_link' => true]) @endif
            @endif
        </x-slot:author>
        <x-slot:meta>
            @if ($thread_is_draft)<x-fruit::badge tone="warning">{{ __('Draft') }}</x-fruit::badge> @if ($thread->isForward()){{ __('are forwarding') }}@endif @endif
            @if ($thread->isNote())<x-fruit::badge tone="warning">{{ __('Note') }}</x-fruit::badge>@endif
            @if (!$thread_is_draft && $thread->isForward())<x-fruit::badge tone="accent">{{ __('Forward') }}</x-fruit::badge>@endif
            @if ($conversation->isPhone() && $thread->first)<x-fruit::badge>{{ __('Phone') }}</x-fruit::badge>@endif
            @if ($thread->isSendStatusError())<x-fruit::badge tone="danger">{{ __('Message not sent to customer') }}</x-fruit::badge>@endif
            {{-- Lines below must be spaceless --}}
            {{ \Eventy::action('thread.after_person_action', $thread, $loop, $threads, $conversation, $mailbox) }}
        </x-slot:meta>
        @if (!$thread_is_draft && ($thread->type != App\Thread::TYPE_NOTE || $thread->isForward()))
            @php
                // Highlight "From" field if "From" header is different from "Reply-To".
                $from_header = $thread->isCustomerMessage() ? $thread->getFromIfDifferentFromReplyTo($customer ?? null) : '';
                // The thread's actual author may differ from the customer the conversation is currently attributed to.
                $owner_mismatch = $thread->isCustomerMessage() && isset($conversation) && $thread->customer_id != $conversation->customer_id;
                $show_from = !App\Nostr\Nostr::isNostr($conversation) && (($thread->isUserMessage() && $thread->from && array_key_exists($thread->from, $mailbox->getAliases()))
                    || ($thread->isCustomerMessage() && isset($customer) && count($customer->emails) > 1)
                    || !empty($from_header)
                    || $owner_mismatch);
                $show_to = ($thread->isForward()
                    || $loop->last
                    || ($thread->type == App\Thread::TYPE_CUSTOMER && count($thread->getToArray($mailbox->getEmails())))
                    || ($thread->type == App\Thread::TYPE_MESSAGE && !in_array($conversation->customer_email, $thread->getToArray()))
                    || ($thread->type == App\Thread::TYPE_MESSAGE && isset($customer) && count($customer->emails) > 1)
                    || \Helper::isPrint())
                    && $thread->getToArray();
                $show_status = $loop->last || ($thread->status != App\Thread::STATUS_NOCHANGE && $thread->status != $threads[$loop->index+1]->status);
                $show_user = $loop->last || $thread->user_id != $threads[$loop->index+1]->user_id || $threads[$loop->index+1]->action_type == App\Thread::ACTION_TYPE_USER_CHANGED;
                $status_line = in_array($thread->type, [App\Thread::TYPE_CUSTOMER, App\Thread::TYPE_MESSAGE, App\Thread::TYPE_NOTE]) ? array_filter([
                    $show_user ? ($thread->user_id ? ($thread->user_cached ? $thread->user_cached->getFullName() : '') : __('Anyone')) : '',
                    $show_status ? $thread->getStatusName() : '',
                ]) : [];
            @endphp
            <x-slot:headers class="thread-recipients">
                @if ($thread->isCustomerMessage() && App\Nostr\Nostr::isNostr($conversation))
                    @include('nostr/partials/thread_sender', ['sender' => App\Nostr\NostrEvent::sender($conversation, $threads, $thread)])
                @endif
                @action('thread.before_recipients', $thread, $loop, $threads, $conversation, $mailbox)
                @if ($show_from)
                    <span @if (!empty($from_header) || $owner_mismatch) class="text-warning" @endif>{{ __("From") }}: @if ($from_header && $from_header != $thread->from){{ $from_header }} → @endif{{ $thread->from }}</span>
                @endif
                @if ($show_to)
                    <span>{{ __("To") }}: {{ implode(', ', $thread->getToArray()) }}</span>
                @endif
                @if ($thread->getCcArray())
                    <span>{{ __("Cc") }}: {{ implode(', ', $thread->getCcArray()) }}</span>
                @endif
                @if ($thread->getBccArray())
                    <span>{{ __("Bcc") }}: {{ implode(', ', $thread->getBccArray()) }}</span>
                @endif
                @if ($status_line)
                    <span class="thread-status">{{ implode(', ', $status_line) }}</span>
                @endif
                @action('thread.after_recipients', $thread, $loop, $threads, $conversation, $mailbox)
            </x-slot:headers>
        @endif
        <x-slot:time>@action('thread.info.prepend', $thread)<a href="#thread-{{ $thread->id }}" class="thread-date" title="{{ $thread_date_title }}">{{ $thread_date }}</a></x-slot:time>

        @action('thread.after_header', $thread, $loop, $threads, $conversation, $mailbox)
        <div class="thread-body">
            @if (!empty($send_status_data['is_bounce']))
                <x-fruit::alert tone="warning">
                    @if (empty($send_status_data['bounce_for_thread']) || empty($send_status_data['bounce_for_conversation']))
                        {{ __('This is a bounce message.') }}
                    @elseif ($bounce_for_conversation = App\Conversation::find($send_status_data['bounce_for_conversation']))
                        {!! __safe_raw_html('This is a bounce message for :link', [
                        'link' => '<a href="'.route('conversations.view', ['id' => $send_status_data['bounce_for_conversation']]).'#thread-id='.$send_status_data['bounce_for_thread'].'">#'.$bounce_for_conversation->number.'</a>'
                        ]) !!}
                    @endif
                </x-fruit::alert>
            @endif
            @if ($thread->isSendStatusError())
                <x-fruit::alert tone="danger">
                    <strong>{{ __('Message not sent to customer') }}</strong>
                    (<a href="{{ route('conversations.ajax_html', array_merge(['action' => 'send_log'], \Request::all(), ['thread_id' => $thread->id])) }}" data-trigger="modal" data-modal-title="{{ __("Outgoing Emails") }}" data-modal-size="lg">{{ __('View log') }}</a>)
                    @if (!empty($send_status_data['bounced_by_thread']) && !empty($send_status_data['bounced_by_conversation']) && ($bounced_by_conversation = App\Conversation::find($send_status_data['bounced_by_conversation'])))
                        <br><small>{!! __safe_raw_html('Message bounced (:link)', [
                        'link' => '<a href="'.route('conversations.view', ['id' => $send_status_data['bounced_by_conversation']]).'#thread-id='.$send_status_data['bounced_by_thread'].'">#'.$bounced_by_conversation->number.'</a>'
                        ]) !!}</small>
                    @endif
                    @if (!empty($send_status_data['msg']))
                        <br><small>{{ $send_status_data['msg'] }}</small>
                    @endif
                    @if ($thread->canRetrySend())
                        <x-slot:actions><button type="button" class="f-button f-button--small btn-thread-retry" data-loading-text="{{ __('Retry') }}…">{{ __('Retry') }}</button></x-slot:actions>
                    @endif
                </x-fruit::alert>
            @endif
            @if ($thread->isForwarded())
                <x-fruit::alert>
                    {{ __('This is a forwarded conversation.') }}
                    {!! __safe_raw_html('Original conversation: :forward_parent_conversation_number', [
                    'forward_parent_conversation_number' => '<a href="'.route('conversations.view', ['id' => $thread->getMetaFw(App\Thread::META_FORWARD_PARENT_CONVERSATION_ID)]).'#thread-'.$thread->getMetaFw(App\Thread::META_FORWARD_PARENT_THREAD_ID).'">#'.$thread->getMetaFw(App\Thread::META_FORWARD_PARENT_CONVERSATION_NUMBER).'</a>'
                    ]) !!}
                </x-fruit::alert>
            @endif
            @if (!$thread_is_draft && $thread->isForward())
                <x-fruit::alert tone="warning">
                    {!! __safe_raw_html(':person forwarded this conversation. Forwarded conversation: :forward_child_conversation_number', [
                    'person' => ucfirst($thread->getForwardByFullName()),
                    'forward_child_conversation_number' => '<a href="'.route('conversations.view', ['id' => $thread->getMetaFw(App\Thread::META_FORWARD_CHILD_CONVERSATION_ID)]).'">#'.$thread->getMetaFw(App\Thread::META_FORWARD_CHILD_CONVERSATION_NUMBER).'</a>'
                    ]) !!}
                </x-fruit::alert>
            @endif

            @if (!$thread_is_draft)
                @include('conversations/partials/ai_translation')
            @endif
            @action('thread.before_body', $thread, $loop, $threads, $conversation, $mailbox)

            <div class="thread-content f-prose" dir="auto">
                @if ($thread_is_draft)
                    {!! safe_raw_html($thread->getCleanBody()) !!}
                @else
                    {!! safe_raw_html(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks(), $thread, $conversation, $mailbox)) !!}
                @endif
            </div>

            @if ($thread->body_original)
                <div class="thread-meta">
                    <span class="f-footnote f-muted">{{ __("Edited by :whom :when", ['whom' => $thread->getEditedByUserName(), 'when' => App\User::dateDiffForHumansWithHours($thread->edited_at)]) }}</span>
                    <a href="#" class="f-footnote thread-original-show">{{ __("Show Original") }}</a><a href="#" class="f-footnote thread-original-hide hidden">{{ __("Hide") }}</a>
                    <div class="thread-original hidden f-prose">{!! safe_raw_html($thread->getCleanBodyOriginal()) !!}</div>
                </div>
            @endif
            @if (!$thread_is_draft)
                @action('thread.meta', $thread, $loop, $threads, $conversation, $mailbox)
            @endif
            @include('conversations/partials/thread_attachments')
        </div>

        @if ($thread->opened_at)
            <x-slot:footer>
                <span class="f-footnote f-muted">{{ __("Customer viewed :when", ['when' => App\User::dateDiffForHumansWithHours($thread->opened_at)]) }}</span>
            </x-slot:footer>
        @endif

        @unless (\Helper::isPrint())
            <x-slot:actions>
                @if ($thread_is_draft)
                    <a class="f-button f-button--small edit-draft-trigger" href="#">{{ __('Edit') }}</a>
                    <a class="f-button f-button--small f-button--ghost discard-draft-trigger" href="#">{{ __('Discard') }}</a>
                @else
                    <x-fruit::menu :title="__('More Actions')" class="thread-options">
                        <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('More Actions')"><x-heroicon-o-ellipsis-horizontal class="f-icon" aria-hidden="true" /></x-slot:trigger>
                        @if ($sender_sent_at = App\Misc\SenderTime::sentAt($thread))
                            {{-- When the customer sent it, their time. --}}
                            <x-fruit::menu-group :label="__('Sent at :time their time (GMT:offset)', ['time' => App\Misc\SenderTime::format($sender_sent_at, $sender_sent_at->getOffsetString()), 'offset' => $sender_sent_at->getOffsetString()])"></x-fruit::menu-group>
                            <x-fruit::menu-separator />
                        @endif
                        @if (Auth::user()->can('edit', $thread))
                            <x-fruit::menu-link href="#" class="thread-edit-trigger">{{ __("Edit") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isNote() && !$thread->first && Auth::user()->can('delete', $thread))
                            <x-fruit::menu-link href="#" class="thread-delete-trigger" :data-loading-text="__('Delete').'…'">{{ __("Delete") }}</x-fruit::menu-link>
                        @endif
                        <x-fruit::menu-link :href="route('conversations.create', ['mailbox_id' => $mailbox->id]).'?from_thread_id='.$thread->id" class="new-conv">{{ __("New Conversation") }}</x-fruit::menu-link>
                        @if ($thread->isCustomerMessage())
                            <x-fruit::menu-link :href="route('conversations.clone_conversation', ['mailbox_id' => $mailbox->id, 'from_thread_id' => $thread->id, 'token' => csrf_token()])" class="new-conv">{{ __("Clone Conversation") }}</x-fruit::menu-link>
                        @endif
                        <ul class="menu-module-items">@action('thread.menu', $thread)</ul>
                        @if (Auth::user()->isAdmin())
                            <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'send_log'], \Request::all(), ['thread_id' => $thread->id]))" data-trigger="modal" :data-modal-title="__('Outgoing Emails')" data-modal-size="lg">{{ __("Outgoing Emails") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isReply())
                            <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'show_original'], \Request::all(), ['thread_id' => $thread->id]))" data-trigger="modal" :data-modal-title="__('Original Message')" data-modal-fit="true" data-modal-size="lg">{{ __("Show Original") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isReply() || $thread->isNote())
                            <x-fruit::menu-link :href="\Request::getRequestUri().'&print_thread_id='.$thread->id.'&print=1'" target="_blank">{{ __("Print") }}</x-fruit::menu-link>
                        @endif
                        <ul class="menu-module-items">@action('thread.menu.append', $thread)</ul>
                    </x-fruit::menu>
                @endif
            </x-slot:actions>
        @endunless
    </x-fruit::message>
@endif

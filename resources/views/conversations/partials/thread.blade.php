@php
    $thread_date_title = App\User::dateFormat($thread->created_at);
    $thread_date = \Helper::isPrint() ? $thread_date_title : App\User::dateDiffForHumans($thread->created_at);
@endphp
{{-- One entry of the conversation's history (x-fruit::thread): an event, or a message from the customer (incoming) or the team (outgoing). --}}
<li wire:key="thread-{{ $thread->id }}">
@if (!empty($editing) && $editing == $thread->id)
    {{-- Edited in place (App\Livewire\ConversationThread). --}}
    @include('conversations/partials/edit_thread')
@elseif ($thread->type == App\Thread::TYPE_LINEITEM)
    {{-- An event between messages (assignments, status changes, merges). --}}
    <x-fruit::message-event class="thread thread-type-lineitem thread-state-{{ $thread->getStateName() }}" id="thread-{{ $thread->id }}" data-thread_id="{{ $thread->id }}" :datetime="$thread->created_at->toIso8601String()">
        {!! safe_raw_html($thread->getActionText('', true, false, null, view('conversations/thread_by', ['thread' => $thread])->render())) !!}
        @action('thread.after_header', $thread, $loop, $threads, $conversation, $mailbox)
        <x-slot:time><a href="#thread-{{ $thread->id }}" class="thread-date" title="{{ $thread_date_title }}">{{ $thread_date }}</a></x-slot:time>
        @if (Auth::user()->isAdmin() && !\Helper::isPrint())
            <x-slot:actions>
                <x-fruit::menu :title="__('More Actions')" class="thread-options">
                    <x-slot:trigger class="f-button--ghost f-button--icon" :aria-label="__('More Actions')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
                    <ul class="menu-module-items">@action('thread.menu', $thread)</ul>
                    <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'send_log'], ($page_query ?? \Request::all()), ['thread_id' => $thread->id]))" data-fruit-dialog-url :data-fruit-dialog-title="__('Outgoing Emails')" data-fruit-dialog-size="large">{{ __("Outgoing Emails") }}</x-fruit::menu-link>
                    <ul class="menu-module-items">@action('thread.menu.append', $thread)</ul>
                </x-fruit::menu>
            </x-slot:actions>
        @endif
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
        // A bounce, delay, complaint or suppression notice: shown as such (partials/delivery_report).
        $delivery_report = $thread_is_draft || !empty($chat) ? null : App\Misc\DeliveryReports::forThread($thread);

        // $older_thread: the one before it (partials/threads).
        $show_status = !$older_thread || ($thread->status != App\Thread::STATUS_NOCHANGE && $thread->status != $older_thread->status);
        $show_user = !$older_thread || $thread->user_id != $older_thread->user_id || $older_thread->action_type == App\Thread::ACTION_TYPE_USER_CHANGED;
        // Next to the author: "You" for the viewer's own, then the status and the assignee the thread left.
        $thread_meta_line = array_filter([
            $thread->type != App\Thread::TYPE_CUSTOMER && $thread->created_by_user_id == Auth::user()->id ? \Illuminate\Support\Str::ucfirst(__('you')) : '',
            !$thread_is_draft && $show_status && in_array($thread->type, [App\Thread::TYPE_CUSTOMER, App\Thread::TYPE_MESSAGE, App\Thread::TYPE_NOTE]) ? $thread->getStatusName() : '',
            !$thread_is_draft && $show_user && in_array($thread->type, [App\Thread::TYPE_CUSTOMER, App\Thread::TYPE_MESSAGE, App\Thread::TYPE_NOTE]) ? ($thread->user_id ? ($thread->user_cached ? __('Assigned to').' '.$thread->user_cached->getFullName() : '') : __('Unassigned')) : '',
        ]);

        // AI Assistant: the message's translation.
        ['wanted' => $ai_translation_wanted, 'language' => $ai_language, 'translation' => $ai_translation] = App\Ai\Translations::forThread($thread, Auth::user());
        // A reply written (or read as a draft) in the viewer's language and sent translated: what
        // was written is the message, the version sent goes beside it.
        $ai_data = $ai_translation && $thread->type == App\Thread::TYPE_MESSAGE ? App\Ai\Summaries::data($thread) : [];
        // (Replies kept before written_in was recorded: their translation was never detected.)
        $ai_written = $ai_data && ($ai_data['written_in'] ?? (empty($ai_data['language']) ? $ai_language : null)) === $ai_language;
        $ai_sent_in = $ai_written ? ($ai_data['sent_in'] ?? $ai_data['language'] ?? null) : null;
    @endphp
    {{-- The chat view ($chat): name, meta and time on one line, the body beside the avatar. --}}
    <x-fruit::message :layout="empty($chat) ? 'stacked' : 'inline'" :continued="!empty($continued) && !empty($chat)" :variant="$thread->isNote() ? 'note' : 'default'" :direction="$thread->type == App\Thread::TYPE_MESSAGE ? 'outgoing' : 'incoming'" :mine="$thread->type == App\Thread::TYPE_MESSAGE && $thread->created_by_user_id == Auth::user()->id" class="thread thread-type-{{ $thread_is_draft ? 'draft' : $thread->getTypeName() }}" id="thread-{{ $thread->id }}" data-thread_id="{{ $thread->id }}" :datetime="$thread->created_at->toIso8601String()" :lang="$ai_written ? $ai_language : ($ai_translation ? (App\Ai\Summaries::data($thread)['language'] ?? null) : null)">
        @if ($ai_written)
            <x-slot:translation :lang="$ai_sent_in"><span class="ai-sent-in">{{ $ai_sent_in ? __('Sent to the customer in :language', ['language' => App\Ai\Settings::displayName($ai_sent_in)]) : __('As sent to the customer') }}</span>{!! safe_raw_html(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks(), $thread, $conversation, $mailbox)) !!}</x-slot:translation>
        @elseif ($ai_translation)
            {{-- The translation below the message, in the user's language. --}}
            <x-slot:translation :lang="$ai_language">@include('conversations/partials/ai_translation')</x-slot:translation>
        @elseif ($ai_translation_wanted && $thread->type == App\Thread::TYPE_CUSTOMER && (App\Ai\Translations::reason($thread, $ai_language)[0] ?? '') == 'waiting')
            {{-- On its way: the translation shows here when it's done (realtime, AiTranslateThread). --}}
            <x-slot:translation><span class="ai-translation-waiting" role="status"><x-fruit::spinner /> {{ __('Translating…') }}</span></x-slot:translation>
        @endif
        @if ($thread->has_attachments)
            <x-slot:attachments>@include('conversations/partials/thread_attachments')</x-slot:attachments>
        @endif
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
                @if (\Helper::isPrint() || !$thread->created_by_user_cached){{ $thread->created_by_user_cached ? $thread->created_by_user_cached->getFullName() : '' }}@else<a href="{{ $thread->created_by_user_cached->url() }}">{{ $thread->created_by_user_cached->getFullName() }}</a>@endif
            @endif
        </x-slot:author>
        <x-slot:meta>
            @if ($thread_is_draft)<x-fruit::badge tone="warning">{{ __('Draft') }}</x-fruit::badge> @if ($thread->isForward()){{ __('are forwarding') }}@endif @endif
            @if ($thread->isNote())<x-fruit::badge tone="warning">{{ __('Note') }}</x-fruit::badge>@endif
            @if (!$thread_is_draft && $thread->isForward())<x-fruit::badge tone="accent">{{ __('Forward') }}</x-fruit::badge>@endif
            @if ($conversation->isPhone() && $thread->first)<x-fruit::badge>{{ __('Phone') }}</x-fruit::badge>@endif
            {{-- The chat view: just the name and time (and the badges above). --}}
            @if (empty($chat) && $thread_meta_line){{ implode(' · ', $thread_meta_line) }}@endif
            {{-- Lines below must be spaceless --}}
            {{ \Eventy::action('thread.after_person_action', $thread, $loop, $threads, $conversation, $mailbox) }}
        </x-slot:meta>
        {{-- Senders and recipients; not in the chat view (the device is in its heading). --}}
        @if (empty($chat) && !$thread_is_draft && ($thread->type != App\Thread::TYPE_NOTE || $thread->isForward()))
            @php
                // Highlight "From" field if "From" header is different from "Reply-To".
                $from_header = $thread->isCustomerMessage() ? $thread->getFromIfDifferentFromReplyTo($customer ?? null) : '';
                // The thread's actual author may differ from the customer the conversation is currently attributed to.
                // (A delivery report's conversation is about the recipient, not its sender.)
                $owner_mismatch = $thread->isCustomerMessage() && isset($conversation) && $thread->customer_id != $conversation->customer_id && !$delivery_report;
                $show_from = !App\Nostr\Nostr::isNostr($conversation) && (($thread->isUserMessage() && $thread->from && array_key_exists($thread->from, $mailbox->getAliases()))
                    || ($thread->isCustomerMessage() && isset($customer) && count($customer->emails) > 1)
                    || !empty($from_header)
                    || $owner_mismatch
                    || $delivery_report);
                $show_to = ($thread->isForward()
                    || $loop->last
                    || ($thread->type == App\Thread::TYPE_CUSTOMER && count($thread->getToArray($mailbox->getEmails())))
                    || ($thread->type == App\Thread::TYPE_MESSAGE && !in_array($conversation->customer_email, $thread->getToArray()))
                    || ($thread->type == App\Thread::TYPE_MESSAGE && isset($customer) && count($customer->emails) > 1)
                    || \Helper::isPrint())
                    && $thread->getToArray();
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
                @action('thread.after_recipients', $thread, $loop, $threads, $conversation, $mailbox)
            </x-slot:headers>
        @endif
        @if ($thread->isSendStatusError())
            {{-- Not delivered: one line under the header; the details are in the log. --}}
            <x-slot:status tone="danger" class="thread-send-error">
                {{ __('Message not sent to customer') }}
                @if (!empty($send_status_data['bounced_by_thread']) && !empty($send_status_data['bounced_by_conversation']) && ($bounced_by_conversation = App\Conversation::find($send_status_data['bounced_by_conversation'])))
                    ({!! __safe_raw_html('Message bounced (:link)', [
                    'link' => '<a href="'.route('conversations.view', ['id' => $send_status_data['bounced_by_conversation']]).'#thread-id='.$send_status_data['bounced_by_thread'].'">#'.$bounced_by_conversation->number.'</a>'
                    ]) !!})
                @elseif (!empty($send_status_data['delivery_problem']['kind']))
                    {{-- Reported by the sending service's webhook (App\Misc\DeliveryReports::recordFromService()). --}}
                    ({!! safe_raw_html(App\Misc\DeliveryReports::headline($send_status_data['delivery_problem']['kind'], e(implode(', ', (array) ($send_status_data['delivery_problem']['recipients'] ?? []))))) !!}: {{ App\Misc\DeliveryReports::reasonText($send_status_data['delivery_problem']['reason'] ?? '') }})
                @endif
                @if ($thread->canRetrySend())
                    <x-fruit::button variant="ghost" class="btn-thread-retry" wire:click="retry({{ $thread->id }})">{{ __('Retry') }}</x-fruit::button>
                @endif
                <x-fruit::button variant="ghost" :data-fruit-dialog-url="route('conversations.ajax_html', array_merge(['action' => 'send_log'], ($page_query ?? \Request::all()), ['thread_id' => $thread->id]))" :data-fruit-dialog-title="__('Outgoing Emails')" data-fruit-dialog-size="large">{{ __('View log') }}</x-fruit::button>
            </x-slot:status>
        @elseif (!$thread_is_draft && ($delivery_notice = App\Misc\DeliveryReports::replyNotice($thread)))
            {{-- Delivered but reported as spam, or delayed (App\Misc\DeliveryReports::markReply()). --}}
            <x-slot:status tone="warning" class="thread-delivery-notice" :data-kind="$delivery_notice['kind']">
                {{ $delivery_notice['text'] }}{!! $delivery_notice['details'] !== '' ? ' ('.safe_raw_html($delivery_notice['details']).')' : '' !!}
                <x-fruit::button variant="ghost" :data-fruit-dialog-url="route('conversations.ajax_html', array_merge(['action' => 'send_log'], ($page_query ?? \Request::all()), ['thread_id' => $thread->id]))" :data-fruit-dialog-title="__('Outgoing Emails')" data-fruit-dialog-size="large">{{ __('View log') }}</x-fruit::button>
            </x-slot:status>
        @endif
        <x-slot:time>@action('thread.info.prepend', $thread)<a href="#thread-{{ $thread->id }}" class="thread-date" title="{{ $thread_date_title }}">{{ $thread_date }}</a></x-slot:time>

        @action('thread.after_header', $thread, $loop, $threads, $conversation, $mailbox)
        <div class="thread-body">
            @if ($delivery_report)
                @include('conversations/partials/delivery_report')
            @elseif (!empty($send_status_data['is_bounce']))
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

            @if (App\Ai\Translations::canForce($thread, Auth::user()))
                {{-- While Translate (its menu) works on this message. --}}
                <p class="f-help ai-translating" wire:loading.flex wire:target="translate({{ $thread->id }})" role="status"><x-fruit::spinner /> {{ __('Translating…') }}</p>
            @endif
            @include('conversations/partials/ai_translation_note')
            @action('thread.before_body', $thread, $loop, $threads, $conversation, $mailbox)

            {{-- A delivery report's own text is in its Delivery Report disclosure. --}}
            @unless ($delivery_report)
                <div class="thread-content f-prose" dir="auto">
                    @if ($thread_is_draft)
                        {!! safe_raw_html($thread->getCleanBody()) !!}
                    @elseif ($ai_written)
                        {!! nl2br(e($ai_translation)) !!}
                    @else
                        {!! safe_raw_html(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks(), $thread, $conversation, $mailbox)) !!}
                    @endif
                </div>
            @endunless

            @if ($thread->body_original)
                <div class="thread-meta" x-data="{ original: false }">
                    <span class="f-footnote f-muted">{{ __("Edited by :whom :when", ['whom' => $thread->getEditedByUserName(), 'when' => App\User::dateDiffForHumansWithHours($thread->edited_at)]) }}</span>
                    <a href="#" class="f-footnote thread-original-show" x-show="!original" x-on:click.prevent="original = true">{{ __("Show Original") }}</a><a href="#" class="f-footnote thread-original-hide" x-show="original" x-cloak x-on:click.prevent="original = false">{{ __("Hide") }}</a>
                    <div class="thread-original f-prose" x-show="original" x-cloak>{!! safe_raw_html(App\Misc\ExternalImages::original($thread)) !!}</div>
                </div>
            @endif
            @if (!$thread_is_draft)
                @action('thread.meta', $thread, $loop, $threads, $conversation, $mailbox)
            @endif
        </div>

        @if ($thread->opened_at)
            <x-slot:footer>
                <span class="f-footnote f-muted">{{ __("Customer viewed :when", ['when' => App\User::dateDiffForHumansWithHours($thread->opened_at)]) }}</span>
            </x-slot:footer>
        @endif

        @unless (\Helper::isPrint())
            <x-slot:actions>
                @if ($thread_is_draft)
                    <button type="button" class="f-button f-button--small edit-draft-trigger" x-data x-on:click="Livewire.dispatch('composer-edit-draft', {thread_id: {{ $thread->id }}})">{{ __('Edit') }}</button>
                    <button type="button" class="f-button f-button--small f-button--ghost discard-draft-trigger" x-data x-on:click="Tallport.confirm({message: Lang.get('messages.confirm_discard_draft'), confirm: Lang.get('messages.discard'), tone: 'danger'}).then(ok => ok && Livewire.dispatch('composer-discard-draft', {thread_id: {{ $thread->id }}}))">{{ __('Discard') }}</button>
                @else
                    <x-fruit::menu :title="__('More Actions')" class="thread-options">
                        <x-slot:trigger class="f-button--ghost f-button--icon f-button--small" :aria-label="__('More Actions')"><x-icon.ellipsis class="f-icon" aria-hidden="true" /></x-slot:trigger>
                        @if ($sender_sent_at = App\Misc\SenderTime::sentAt($thread))
                            {{-- When the customer sent it, their time. --}}
                            <x-fruit::menu-group :label="__('Sent at :time their time (GMT:offset)', ['time' => App\Misc\SenderTime::format($sender_sent_at, $sender_sent_at->getOffsetString()), 'offset' => $sender_sent_at->getOffsetString()])"></x-fruit::menu-group>
                            <x-fruit::menu-separator />
                        @endif
                        @if (App\Ai\Translations::canForce($thread, Auth::user()))
                            <x-fruit::menu-link href="#" class="thread-translate-trigger" wire:click.prevent="translate({{ $thread->id }})">{{ __('Translate') }}</x-fruit::menu-link>
                        @endif
                        @if (Auth::user()->can('edit', $thread))
                            <x-fruit::menu-link href="#" class="thread-edit-trigger" wire:click.prevent="edit({{ $thread->id }})">{{ __("Edit") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isNote() && !$thread->first && Auth::user()->can('delete', $thread))
                            <x-fruit::menu-link href="#" class="thread-delete-trigger" wire:click.prevent="deleteNote({{ $thread->id }})">{{ __("Delete") }}</x-fruit::menu-link>
                        @endif
                        <x-fruit::menu-link :href="route('conversations.create', ['mailbox_id' => $mailbox->id]).'?from_thread_id='.$thread->id" class="new-conv">{{ __("New Conversation") }}</x-fruit::menu-link>
                        @if ($thread->isCustomerMessage())
                            <x-fruit::menu-link :href="route('conversations.clone_conversation', ['mailbox_id' => $mailbox->id, 'from_thread_id' => $thread->id, 'token' => csrf_token()])" class="new-conv">{{ __("Clone Conversation") }}</x-fruit::menu-link>
                        @endif
                        <ul class="menu-module-items">@action('thread.menu', $thread)</ul>
                        @if (Auth::user()->isAdmin())
                            <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'send_log'], ($page_query ?? \Request::all()), ['thread_id' => $thread->id]))" data-fruit-dialog-url :data-fruit-dialog-title="__('Outgoing Emails')" data-fruit-dialog-size="large">{{ __("Outgoing Emails") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isReply())
                            <x-fruit::menu-link :href="route('conversations.ajax_html', array_merge(['action' => 'show_original'], ($page_query ?? \Request::all()), ['thread_id' => $thread->id]))" data-fruit-dialog-url :data-fruit-dialog-title="__('Original Message')" data-fruit-dialog-size="large">{{ __("Show Original") }}</x-fruit::menu-link>
                        @endif
                        @if ($thread->isReply() || $thread->isNote())
                            <x-fruit::menu-link :href="($page_uri ?? \Request::getRequestUri()).'&print_thread_id='.$thread->id.'&print=1'" target="_blank">{{ __("Print") }}</x-fruit::menu-link>
                        @endif
                        <ul class="menu-module-items">@action('thread.menu.append', $thread)</ul>
                    </x-fruit::menu>
                @endif
            </x-slot:actions>
        @endunless
    </x-fruit::message>
@endif
</li>

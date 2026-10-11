@if (count($conversations) || (isset($params) && !empty($params['user_id'])))
    @php
        if (is_array($conversations)) {
            $conversations = collect($conversations);
        }
        if (empty($folder)) {
            // Create dummy folder
            $folder = new App\Folder();
            $folder->type = App\Folder::TYPE_ASSIGNED;
        }
        // Clean filter.
        if (!empty($conversations_filter)) {
            foreach ($conversations_filter as $i => $filter_value) {
                if (is_array($filter_value)) {
                    unset($conversations_filter[$i]);
                }
            }
        }

        // Preload users and customers
        App\Conversation::loadUsers($conversations);
        App\Conversation::loadCustomers($conversations);

        // Get information on viewers
        if (empty($no_checkboxes)) {
            $viewers = App\Conversation::getViewersInfo($conversations, ['id', 'first_name', 'last_name'], [Auth::user()->id]);
        }

        $conversations = \Eventy::filter('conversations_table.preload_table_data', $conversations);
        $show_assigned = ($folder->type == App\Folder::TYPE_ASSIGNED || $folder->type == App\Folder::TYPE_CLOSED || !array_key_exists($folder->type, App\Folder::$types));

        if (!isset($params)) {
            $params = [];
        }

        // For customer profile.
        if (!empty($params['no_customer'])) {
            $no_customer = true;
        }
        if (!empty($params['no_checkboxes'])) {
            $no_checkboxes = true;
        }

        // Sorting.
        $sorting = App\Conversation::getConvTableSorting(null, $folder->id ? $folder : null);

    @endphp

    @php
        $column_title_date = $folder->type == App\Folder::TYPE_CLOSED ? __("Closed") : ($folder->type == App\Folder::TYPE_DRAFTS ? __("Last Updated") : ($folder->type == App\Folder::TYPE_DELETED ? __("Deleted") : \Eventy::filter('conversations_table.column_title_date', __("Waiting Since"), $folder)));
        $sort_titles = ['date' => $column_title_date, 'activity' => __('Last Activity'), 'number' => __("Number"), 'subject' => __("Conversation")];
        $sort_by = array_key_exists($sorting['sort_by'], $sort_titles) ? $sorting['sort_by'] : 'date';
        $sort_order = $sorting['order'] ?: 'asc';
        // Rows open conversations on the list's page (split view).
        $list_params = (method_exists($conversations, 'currentPage') && $conversations->currentPage() > 1) ? ['list_page' => $conversations->currentPage()] : [];
        $list_mailbox_id = $folder->id < 0 ? $folder->id : $folder->mailbox_id;
        $current_conversation_id = $params['current_conversation_id'] ?? null;
    @endphp
    <section class="table-conversations conv-list @if (!empty($params['show_mailbox']))show-mailbox @endif" aria-label="{{ __('Conversations') }}" data-page="{{ method_exists($conversations, 'currentPage') ? $conversations->currentPage() : (int) request()->get('page', 1) }}" @if ($folder->id) data-folder_id="{{ $folder->id }}" data-mailbox_id="{{ $list_mailbox_id }}" @endif>
        {{-- The list header: view tools, or while conversations are selected, the selection bar in their place.
             Cmd/Ctrl+click, Shift+click, Shift+arrows and Cmd/Ctrl+A select rows. --}}
        <x-fruit::list-header class="conv-list__header">
            <x-fruit::menu :title="__('Sort By')" class="conv-list__sort">
                <x-slot:trigger class="f-button--ghost f-button--small">{{ $sort_titles[$sort_by] }} {{ $sort_order == 'desc' ? '↑' : '↓' }}</x-slot:trigger>
                @foreach ($sort_titles as $sort_field => $sort_title)
                    <x-fruit::menu-link href="#" class="conv-col-sort" wire:click.prevent="sort('{{ $sort_field }}')" :aria-current="$sort_by == $sort_field ? 'true' : null">{{ $sort_title }}@if ($sort_by == $sort_field) {{ $sort_order == 'desc' ? '↑' : '↓' }}@endif</x-fruit::menu-link>
                @endforeach
            </x-fruit::menu>
            @if ($show_assigned)
                @php
                    $assignees = App\User::assigneeFilterUsers(Auth::user(), $folder->id > 0 ? $folder->mailbox : null);
                    $filter_assignee = !empty($params['user_id']) ? $assignees->firstWhere('id', (int) $params['user_id']) : null;
                @endphp
                <x-fruit::menu :title="__('Assigned To')" class="conv-owner @if (!empty($params['user_id'])) filtered @endif">
                    <x-slot:trigger class="f-button--ghost f-button--small"><x-icon.funnel class="f-icon" aria-hidden="true" /> {{ $filter_assignee ? $filter_assignee->getFullName() : __('Assigned To') }}</x-slot:trigger>
                    <x-fruit::menu-link href="#" wire:click.prevent="filterAssignee" :aria-current="empty($params['user_id']) ? 'true' : null">{{ __('Anyone') }}</x-fruit::menu-link>
                    <x-fruit::menu-separator />
                    @foreach ($assignees as $assignee)
                        <x-fruit::menu-link href="#" wire:click.prevent="filterAssignee({{ $assignee->id }})" :aria-current="($params['user_id'] ?? null) == $assignee->id ? 'true' : null">{{ $assignee->getFullName() }}</x-fruit::menu-link>
                    @endforeach
                </x-fruit::menu>
            @endif
            @if (empty($no_checkboxes))
                <x-slot:selection>
                    @include('conversations/partials/bulk_actions')
                </x-slot:selection>
            @endif
        </x-fruit::list-header>

        <div class="f-pane__scroll split-view__list">
        <x-fruit::item-list class="conv-list__items" :id="empty($no_checkboxes) ? 'conversations' : null" :selection="empty($no_checkboxes) ? 'multiple' : 'none'" :aria-label="__('Conversations')">
            @php
                // The rows' menus (App\Livewire\ConversationList only): a selected row's acts on the selection.
                $row_menus = isset($selected) && empty($no_checkboxes);
                $row_selection = $row_menus ? $conversations->whereIn('id', array_map('intval', (array) $selected)) : collect();
                $unread_ids = App\ConversationRead::unreadIds($conversations, Auth::user());
                // A row's status unless it's Active (most are), and not where all rows share one.
                $show_status = !in_array($folder->type, [App\Folder::TYPE_CLOSED, App\Folder::TYPE_SPAM]);
            @endphp
            @foreach ($conversations as $conversation)
                @php
                    $conv_target = (!empty(request()->x_embed) || !empty($params['target_blank']));
                    // The time shown: what the list is sorted by (Last Activity), else the folder's own.
                    $conv_waiting_since = $sort_by == 'activity' && $conversation->last_activity_at ? App\User::dateDiffForHumans($conversation->last_activity_at) : $conversation->getWaitingSince($folder);
                    $conv_date_title = !in_array($folder->type, [App\Folder::TYPE_CLOSED, App\Folder::TYPE_DRAFTS, App\Folder::TYPE_DELETED]) ? strip_tags(str_replace('<br/>', ' ', $conversation->getDateTitle())) : '';
                    $conv_customer_name = ($conversation->customer_id && $conversation->customer) ? $conversation->customer->getFullName(true) : $conversation->customer_email;
                    $ai_one_liner = $conversation->search_snippet === null ? (App\Ai\Summaries::getAny($conversation, App\Ai\Settings::language($conversation->mailbox_cached, Auth::user()))['one_liner'] ?? '') : '';
                    // Not in Mine: the viewer's own.
                    $conv_assignee = ($conversation->user_id && $folder->type != App\Folder::TYPE_MINE) ? $conversation->user : null;
                    // The channel's token: its icon, and the number of messages when there's more than one.
                    $conv_channel = $conversation->hasChannel() ? ($conversation->getChannelName() ?: __('Chat')) : ($conversation->isPhone() ? __('Phone') : __('Email'));
                    $conv_menu_rows = $row_selection->contains('id', $conversation->id) ? $row_selection : collect([$conversation]);
                    $conv_channel_label = trans_choice(':channel, :count message|:channel, :count messages', $conversation->threads_count, ['channel' => $conv_channel]);
                @endphp
                <li class="conv-row @action('conversations_table.row_class', $conversation) @if ($conversation->isActive()) conv-active @endif @if ($conversation->isSpam()) conv-spam @endif" data-conversation_id="{{ $conversation->id }}" wire:key="conv-{{ $conversation->id }}">
                    @if (empty($no_checkboxes))
                        <x-fruit::checkbox class="conv-checkbox" :id="'cb-'.$conversation->id" :name="'cb_'.$conversation->id" :value="$conversation->id" wire:model.live="selected"><span class="f-sr-only">{{ __('Select Conversation') }}: {{ $conversation->getSubject() }}</span></x-fruit::checkbox>
                    @endif
                    {{-- Across mailboxes: a bar in the mailbox's color instead of its name (the sidebar's icons are the legend). --}}
                    <x-fruit::item-link :href="$conversation->url(null, null, $list_params)" :current="$current_conversation_id == $conversation->id" :unread="in_array($conversation->id, $unread_ids)" :target="$conv_target ? '_blank' : null" class="conv-row__link"
                        :mark="!empty($params['show_mailbox']) ? ($conversation->mailbox_cached->accent ?: 'blue') : null"
                        :mark-label="!empty($params['show_mailbox']) ? __(':name mailbox', ['name' => $conversation->mailbox_cached->name]) : null">
                        <x-slot:title :title="$conversation->customer_email">@if (empty($no_customer)){{ $conv_customer_name }}@else{{ $conversation->getSubject() }}@endif</x-slot:title>
                        <x-slot:trailing :title="$conv_date_title ?: null">{{ $conv_waiting_since }}</x-slot:trailing>
                        @if (empty($no_customer))
                            <x-slot:subtitle>@include('conversations/partials/badges'){{ '' }}@action('conversations_table.before_subject', $conversation){{ $conversation->getSubject() }}@action('conversations_table.after_subject', $conversation)</x-slot:subtitle>
                        @endif
                        <x-slot:preview>@action('conversations_table.preview_prepend', $conversation)@if ($conversation->search_snippet !== null)<span class="search-snippet">{!! $conversation->search_snippet !!}</span>@elseif ($ai_one_liner){{ $ai_one_liner }}@elseif ($conversation->preview){{ $conversation->preview }}@endif</x-slot:preview>
                        <x-slot:meta class="conv-row__meta">
                            {{-- The number in search results only. --}}@if (!empty($params['show_number']))<span class="conv-number">#{{ $conversation->number }}</span>@endif
                            <span class="conv-channel" title="{{ $conv_channel_label }}">@if ($conversation->hasChannel())<x-icon.message-square class="f-icon" aria-hidden="true" />@elseif ($conversation->isPhone())<x-icon.phone class="f-icon" aria-hidden="true" />@else<x-icon.mail class="f-icon" aria-hidden="true" />@endif@if ($conversation->threads_count > 1)<span aria-hidden="true">{{ $conversation->threads_count }}</span>@endif<span class="f-sr-only">{{ $conv_channel_label }}</span></span>
                            @if ($conv_assignee)<span class="conv-owner-name"><x-icon.user class="f-icon" aria-hidden="true" /> <span class="conv-owner-name__text">{{ $conv_assignee->getFullName() }}</span></span>@endif
                            @if ($conversation->has_attachments)<x-icon.paperclip class="f-icon" :aria-label="__('Attachments')" role="img" />@endif
                            @if (!empty($viewers[$conversation->id]))
                                <span class="viewer-badge @if (!empty($viewers[$conversation->id]['replying'])) viewer-replying @endif"><x-icon.eye class="f-icon" aria-hidden="true" /> {{ implode(', ', array_map(function ($viewer) { return __($viewer['replying'] ? ':user is replying' : ':user is viewing', ['user' => $viewer['user']->getFullName()]); }, $viewers[$conversation->id]['users'])) }}</span>
                            @endif
                            @if ($show_status && (int) $conversation->status != App\Conversation::STATUS_ACTIVE)
                                <span class="conv-row__status">@include('conversations/partials/status_dot', ['status' => (int) $conversation->status]){{ App\Conversation::statusCodeToName($conversation->status) }}</span>
                            @endif
                        </x-slot:meta>
                    </x-fruit::item-link>
                    @if ($row_menus)
                        {{-- The selection bar and the conversation's toolbar have the same commands. Keyed by what it acts on, so its label follows. --}}
                        <x-fruit::context-menu wire:key="conv-menu-{{ $conversation->id }}-{{ $conv_menu_rows->pluck('id')->implode('-') }}"
                            :title="count($conv_menu_rows) > 1 ? trans_choice('Actions for :count conversation|Actions for :count conversations', count($conv_menu_rows)) : __('Conversation Actions')">
                            <x-fruit::menu-link :href="$conversation->url()" target="_blank">{{ __('Open in New Tab') }}</x-fruit::menu-link>
                            <x-fruit::menu-item x-on:click="copyToClipboard({{ \Illuminate\Support\Js::from($conversation->url()) }}); Tallport.toast({{ \Illuminate\Support\Js::from(__('Copied')) }})">{{ __('Copy Link') }}</x-fruit::menu-item>
                            <x-fruit::menu-separator />
                            @php $conv_menu_read = $conv_menu_rows->contains(fn ($row) => in_array($row->id, $unread_ids)); @endphp
                            <x-fruit::menu-item wire:click="rowRead({{ $conversation->id }}, {{ $conv_menu_read ? 1 : 0 }})">{{ $conv_menu_read ? __('Mark as Read') : __('Mark as Unread') }}</x-fruit::menu-item>
                            @php $conv_menu_star = !$conv_menu_rows->every(fn ($row) => $row->isStarredByUser()); @endphp
                            <x-fruit::menu-item wire:click="rowStar({{ $conversation->id }}, {{ $conv_menu_star ? 1 : 0 }})">{{ $conv_menu_star ? __('Star') : __('Unstar') }}</x-fruit::menu-item>
                            <x-fruit::menu-item wire:click="rowAssignToMe({{ $conversation->id }})">{{ __('Assign to Me') }}</x-fruit::menu-item>
                            <x-fruit::menu-separator />
                            @php $conv_menu_close = !$conv_menu_rows->every(fn ($row) => $row->status == App\Conversation::STATUS_CLOSED); @endphp
                            @if ($conv_menu_close || !$conv_menu_rows->every(fn ($row) => $row->isChatUnavailable()))
                                <x-fruit::menu-item wire:click="rowClose({{ $conversation->id }}, {{ $conv_menu_close ? 1 : 0 }})">{{ $conv_menu_close ? __('Close') : __('Reopen') }}</x-fruit::menu-item>
                            @endif
                        </x-fruit::context-menu>
                    @endif
                </li>
            @endforeach
        </x-fruit::item-list>

        @if (count($conversations))
            <x-fruit::pagination class="conv-list__footer" :aria-label="__('Conversations')">
                <span class="f-muted conv-totals">
                    @if ($conversations->total())
                        {!! __safe_raw_html(':count conversations', ['count' => '<strong>'.$conversations->total().'</strong>']) !!} ·
                    @endif
                    @if (isset($folder->active_count) && !$folder->isIndirect())
                        <strong>{{ $folder->getActiveCount() }}</strong> {{ __('active') }} ·
                    @endif
                    <strong>{{ $conversations->firstItem() }}</strong>–<strong>{{ $conversations->lastItem() }}</strong>
                </span>
                {{ $conversations->links('conversations/conversations_pagination', ['wire' => true]) }}
            </x-fruit::pagination>
        @endif
        </div>
    </section>
@else
    <x-fruit::empty-state>
        <x-slot:icon><x-icon.inbox /></x-slot:icon>
        {{ __('There are no conversations here') }}
    </x-fruit::empty-state>
@endif

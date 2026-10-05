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
        $sorting = App\Conversation::getConvTableSorting();

    @endphp

    @php
        $column_title_date = $folder->type == App\Folder::TYPE_CLOSED ? __("Closed") : ($folder->type == App\Folder::TYPE_DRAFTS ? __("Last Updated") : ($folder->type == App\Folder::TYPE_DELETED ? __("Deleted") : \Eventy::filter('conversations_table.column_title_date', __("Waiting Since"), $folder)));
        $sort_titles = ['date' => $column_title_date, 'number' => __("Number"), 'subject' => __("Conversation")];
        $sort_by = array_key_exists($sorting['sort_by'], $sort_titles) ? $sorting['sort_by'] : 'date';
        $sort_order = $sorting['order'] ?: 'asc';
        // Rows open conversations on the list's page (split view).
        $list_params = (method_exists($conversations, 'currentPage') && $conversations->currentPage() > 1) ? ['list_page' => $conversations->currentPage()] : [];
        $list_mailbox_id = $folder->id < 0 ? $folder->id : $folder->mailbox_id;
        $current_conversation_id = $params['current_conversation_id'] ?? null;
    @endphp
    <section class="table-conversations conv-list @if (!empty($params['show_mailbox']))show-mailbox @endif" aria-label="{{ __('Conversations') }}" data-page="{{ method_exists($conversations, 'currentPage') ? $conversations->currentPage() : (int) request()->get('page', 1) }}" @if ($folder->id) data-folder_id="{{ $folder->id }}" data-mailbox_id="{{ $list_mailbox_id }}" @endif>
        {{-- The list header: view tools, or while conversations are selected, the selection bar in their place.
             Cmd/Ctrl+click and Shift+click select rows; Select shows the checkboxes for touch. --}}
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
                <span class="f-toolbar__spacer"></span>
                <x-fruit::button variant="ghost" size="small" data-fruit-select-toggle aria-controls="conversations" aria-pressed="false">{{ __('Select') }}</x-fruit::button>
                <x-slot:selection>
                    @include('conversations/partials/bulk_actions')
                </x-slot:selection>
            @endif
        </x-fruit::list-header>

        <div class="f-pane__scroll split-view__list">
        <x-fruit::item-list class="conv-list__items" :id="empty($no_checkboxes) ? 'conversations' : null" :selection="empty($no_checkboxes) ? 'multiple' : 'none'" :aria-label="__('Conversations')">
            @foreach ($conversations as $conversation)
                @php
                    $conv_target = (!empty(request()->x_embed) || !empty($params['target_blank']));
                    $conv_waiting_since = $conversation->getWaitingSince($folder);
                    $conv_date_title = !in_array($folder->type, [App\Folder::TYPE_CLOSED, App\Folder::TYPE_DRAFTS, App\Folder::TYPE_DELETED]) ? strip_tags(str_replace('<br/>', ' ', $conversation->getDateTitle())) : '';
                    $conv_customer_name = ($conversation->customer_id && $conversation->customer) ? $conversation->customer->getFullName(true) : $conversation->customer_email;
                    $ai_one_liner = $conversation->search_snippet === null ? (App\Ai\Summaries::getAny($conversation, App\Ai\Settings::language($conversation->mailbox_cached, Auth::user()))['one_liner'] ?? '') : '';
                    $conv_starred = $conversation->isStarredByUser();
                @endphp
                <li class="conv-row @action('conversations_table.row_class', $conversation) @if ($conversation->isActive()) conv-active @endif @if ($conversation->isSpam()) conv-spam @endif" data-conversation_id="{{ $conversation->id }}" wire:key="conv-{{ $conversation->id }}">
                    @if (empty($no_checkboxes))
                        <x-fruit::checkbox class="conv-checkbox" :id="'cb-'.$conversation->id" :name="'cb_'.$conversation->id" :value="$conversation->id" wire:model.live="selected"><span class="f-sr-only">{{ __('Select Conversation') }}: {{ $conversation->getSubject() }}</span></x-fruit::checkbox>
                    @endif
                    <x-fruit::item-link :href="$conversation->url(null, null, $list_params)" :current="$current_conversation_id == $conversation->id" :target="$conv_target ? '_blank' : null" class="conv-row__link">
                        <x-slot:title :title="$conversation->customer_email">@if (empty($no_customer)){{ $conv_customer_name }}@else{{ $conversation->getSubject() }}@endif</x-slot:title>
                        <x-slot:trailing :title="$conv_date_title ?: null">{{ $conv_waiting_since }}</x-slot:trailing>
                        @if (empty($no_customer))
                            <x-slot:subtitle>@include('conversations/partials/badges'){{ '' }}@if ($conversation->hasChannel() && $conversation->getChannelName())<span class="f-badge conv-channel">{{ $conversation->getChannelName() }}</span> @endif{{ '' }}@action('conversations_table.before_subject', $conversation){{ $conversation->getSubject() }}@action('conversations_table.after_subject', $conversation)</x-slot:subtitle>
                        @endif
                        <x-slot:preview>@action('conversations_table.preview_prepend', $conversation)@if ($conversation->search_snippet !== null)<span class="search-snippet">{!! $conversation->search_snippet !!}</span>@elseif ($ai_one_liner)<x-icon.sparkles class="f-icon ai-assistant-icon" role="img" :aria-label="__('AI Assistant')" /> {{ $ai_one_liner }}@elseif ($conversation->preview){{ $conversation->preview }}@endif</x-slot:preview>
                        <x-slot:meta class="conv-row__meta">
                            <span class="conv-number">#{{ $conversation->number }}</span>
                            @if ($conversation->threads_count > 1)<span class="conv-counter" title="{{ __('Messages') }}"><x-icon.messages-square class="f-icon" aria-hidden="true" /> {{ $conversation->threads_count }}</span>@endif
                            @if (!empty($params['show_mailbox']))<span>{{ $conversation->mailbox_cached->name }}</span>@endif
                            @if ($conversation->user_id && ($assignee = $conversation->user))<span class="conv-owner-name"><x-icon.user class="f-icon" aria-hidden="true" /> {{ $assignee->getFullName() }}</span>@endif
                            @if ($conversation->has_attachments)<x-icon.paperclip class="f-icon" :aria-label="__('Attachments')" role="img" />@endif
                            @if ($conversation->isPhone())<x-icon.phone class="f-icon" aria-hidden="true" />@endif
                            @if (!empty($viewers[$conversation->id]))
                                <span class="viewer-badge @if (!empty($viewers[$conversation->id]['replying'])) viewer-replying @endif"><x-icon.eye class="f-icon" aria-hidden="true" /> {{ implode(', ', array_map(function ($viewer) { return __($viewer['replying'] ? ':user is replying' : ':user is viewing', ['user' => $viewer['user']->getFullName()]); }, $viewers[$conversation->id]['users'])) }}</span>
                            @endif
                        </x-slot:meta>
                    </x-fruit::item-link>
                    @if (empty($no_checkboxes))
                        <x-fruit::button variant="ghost" size="small" class="f-button--icon conv-star" wire:click="star({{ $conversation->id }})" :aria-pressed="$conv_starred ? 'true' : 'false'" :aria-label="__('Star Conversation')" :title="$conv_starred ? __('Unstar Conversation') : __('Star Conversation')"><x-icon.star class="f-icon conv-star__off" aria-hidden="true" /><x-icon.star fill="currentColor" class="f-icon conv-star__on" aria-hidden="true" /></x-fruit::button>
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

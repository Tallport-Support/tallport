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
    <section class="table-conversations conv-list @if (!empty($params['show_mailbox']))show-mailbox @endif" aria-label="{{ __('Conversations') }}" data-page="{{ method_exists($conversations, 'currentPage') ? $conversations->currentPage() : (int) request()->get('page', 1) }}" @if ($folder->id) data-folder_id="{{ $folder->id }}" data-mailbox_id="{{ $list_mailbox_id }}" @endif @foreach ($params as $param_name => $param_value) data-param_{{ $param_name }}="{{ $param_value }}" @endforeach @if (!empty($conversations_filter)) @foreach ($conversations_filter as $filter_field => $filter_value) data-filter_{{ $filter_field }}="{{ $filter_value }}" @endforeach @endif @foreach ($sorting as $sorting_name => $sorting_value) data-sorting_{{ $sorting_name }}="{{ $sorting_value }}" @endforeach >
        @if (empty($no_checkboxes))
            @include('/conversations/partials/bulk_actions')
        @endif

        <div class="conv-list__toolbar f-row">
            @if (empty($no_checkboxes))
                <input type="checkbox" class="f-check toggle-all" id="toggle-all" aria-label="{{ __('Select All Conversations') }}" title="{{ __('Select All Conversations') }}">
            @endif
            <x-fruit::menu :title="__('Sort by')" class="conv-list__sort">
                <x-slot:trigger class="f-button--ghost f-button--small">{{ $sort_titles[$sort_by] }} {{ $sort_order == 'desc' ? '↑' : '↓' }}</x-slot:trigger>
                @foreach ($sort_titles as $sort_field => $sort_title)
                    <x-fruit::menu-link href="#" class="conv-col-sort" :data-sort-by="$sort_field" :data-order="$sort_by == $sort_field ? $sort_order : 'desc'" :aria-current="$sort_by == $sort_field ? 'true' : null">{{ $sort_title }}@if ($sort_by == $sort_field) {{ $sort_order == 'desc' ? '↑' : '↓' }}@endif</x-fruit::menu-link>
                @endforeach
            </x-fruit::menu>
            @if ($show_assigned)
                <button type="button" class="f-button f-button--ghost f-button--small conv-owner fs-trigger-modal @if (!empty($params['user_id'])) filtered @endif" data-remote="{{ route('conversations.ajax_html', ['action' =>
                        'assignee_filter', 'mailbox_id' => (\Helper::isRoute('mailboxes.view.folder') ? $folder->mailbox_id : ''), 'user_id' => ($params['user_id'] ?? '')]) }}" data-trigger="modal" data-modal-title="{{ __("Assigned To") }}" data-modal-no-footer="true" data-modal-on-show="initConvAssigneeFilter" @if (!empty($params['user_id'])) aria-pressed="true" @endif><x-heroicon-o-funnel class="f-icon" aria-hidden="true" /> {{ __("Assigned To") }}</button>
            @endif
        </div>

        <ul class="f-item-list conv-list__items" role="list">
            @foreach ($conversations as $conversation)
                @php
                    $conv_target = (!empty(request()->x_embed) || !empty($params['target_blank']));
                    $conv_waiting_since = $conversation->getWaitingSince($folder);
                    $conv_date_title = !in_array($folder->type, [App\Folder::TYPE_CLOSED, App\Folder::TYPE_DRAFTS, App\Folder::TYPE_DELETED]) ? $conversation->getDateTitle() : '';
                    $conv_customer_name = ($conversation->customer_id && $conversation->customer) ? $conversation->customer->getFullName(true) : $conversation->customer_email;
                    $ai_one_liner = $conversation->search_snippet === null ? (App\Ai\Summaries::getAny($conversation, App\Ai\Settings::language($conversation->mailbox_cached, Auth::user()))['one_liner'] ?? '') : '';
                    $conv_starred = $conversation->isStarredByUser();
                @endphp
                <li class="conv-row @action('conversations_table.row_class', $conversation) @if ($conversation->isActive()) conv-active @endif @if ($conversation->isSpam()) conv-spam @endif" data-conversation_id="{{ $conversation->id }}">
                    @if (empty($no_checkboxes))
                        <input type="checkbox" class="f-check conv-checkbox" id="cb-{{ $conversation->id }}" name="cb_{{ $conversation->id }}" value="{{ $conversation->id }}" aria-label="{{ __('Select Conversation') }}: {{ $conversation->getSubject() }}">
                    @endif
                    <a href="{{ $conversation->url(null, null, $list_params) }}" class="f-item-row conv-row__link" @if ($conv_target) target="_blank" @endif @if ($current_conversation_id == $conversation->id) aria-current="true" @endif>
                        <span class="f-item-row__top">
                            <strong class="f-item-row__title" title="{{ $conversation->customer_email }}">@if (empty($no_customer)){{ $conv_customer_name }}@else{{ $conversation->getSubject() }}@endif</strong>
                            <span class="f-item-row__time" @if ($conv_date_title) title="{{ strip_tags(str_replace('<br/>', ' ', $conv_date_title)) }}" @endif>{{ $conv_waiting_since }}</span>
                        </span>
                        @if (empty($no_customer))
                            <span class="f-item-row__subtitle">@include('conversations/partials/badges'){{ '' }}@if ($conversation->isChat() && $conversation->getChannelName())<span class="f-badge conv-channel">{{ $conversation->getChannelName() }}</span> @endif{{ '' }}@action('conversations_table.before_subject', $conversation){{ $conversation->getSubject() }}@action('conversations_table.after_subject', $conversation)</span>
                        @endif
                        <span class="f-item-row__preview">@action('conversations_table.preview_prepend', $conversation)@if ($conversation->search_snippet !== null)<span class="search-snippet">{!! $conversation->search_snippet !!}</span>@elseif ($ai_one_liner)<span class="ai-assistant-badge">AI</span> {{ $ai_one_liner }}@elseif ($conversation->preview){{ $conversation->preview }}@endif</span>
                        <span class="f-item-row__meta conv-row__meta">
                            <span class="conv-number">#{{ $conversation->number }}</span>
                            @if ($conversation->threads_count > 1)<span class="conv-counter" title="{{ __('Messages') }}"><x-heroicon-o-chat-bubble-left-right class="f-icon" aria-hidden="true" /> {{ $conversation->threads_count }}</span>@endif
                            @if (!empty($params['show_mailbox']))<span>{{ $conversation->mailbox_cached->name }}</span>@endif
                            @if ($conversation->user_id && ($assignee = $conversation->user))<span class="conv-owner-name"><x-heroicon-o-user class="f-icon" aria-hidden="true" /> {{ $assignee->getFullName() }}</span>@endif
                            @if ($conversation->has_attachments)<x-heroicon-o-paper-clip class="f-icon" :aria-label="__('Attachments')" role="img" />@endif
                            @if ($conversation->isPhone())<x-heroicon-o-phone class="f-icon" aria-hidden="true" />@endif
                            @if (!empty($viewers[$conversation->id]))
                                <span class="viewer-badge @if (!empty($viewers[$conversation->id]['replying'])) viewer-replying @endif"><x-heroicon-o-eye class="f-icon" aria-hidden="true" /> {{ implode(', ', array_map(function ($viewer) { return __($viewer['replying'] ? ':user is replying' : ':user is viewing', ['user' => $viewer['user']->getFullName()]); }, $viewers[$conversation->id]['users'])) }}</span>
                            @endif
                        </span>
                    </a>
                    @if (empty($no_checkboxes))
                        <button type="button" class="f-button f-button--ghost f-button--icon conv-star" aria-pressed="{{ $conv_starred ? 'true' : 'false' }}" aria-label="{{ __('Star Conversation') }}" title="@if ($conv_starred){{ __("Unstar Conversation") }}@else{{ __("Star Conversation") }}@endif"><x-heroicon-o-star class="f-icon conv-star__off" aria-hidden="true" /><x-heroicon-s-star class="f-icon conv-star__on" aria-hidden="true" /></button>
                    @endif
                </li>
            @endforeach
        </ul>

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
                {{ $conversations->links('conversations/conversations_pagination') }}
            </x-fruit::pagination>
        @endif
    </section>
@else
    <x-fruit::empty-state>
        <x-slot:icon><x-heroicon-o-inbox /></x-slot:icon>
        {{ __('There are no conversations here') }}
    </x-fruit::empty-state>
@endif

@section('javascript')
    @parent
    conversationsTableInit();
@endsection

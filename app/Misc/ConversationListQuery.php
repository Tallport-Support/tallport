<?php

namespace App\Misc;

use App\Conversation;
use App\Folder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The conversation list query shared by the AJAX list and Livewire list.
 */
class ConversationListQuery
{
    public function folderConversationId($folder, $user)
    {
        $memo = request()->attributes->get('folder_conversation_id', []);
        $key = $folder->id.'-'.$user->id;
        if (!array_key_exists($key, $memo)) {
            $list = $this->folderList($folder, $user);
            $conversation_id = null;
            if (count($list['conversations'])) {
                $query = $folder->id < 0 ? AllMailboxes::query($folder, $user) : Conversation::getQueryByFolder($folder, $user->id);
                $conversation_id = session()->get('folder_conversation.'.$folder->id);
                if (!$conversation_id || !$query->where('conversations.id', $conversation_id)->exists()) {
                    $conversation_id = $list['conversations']->first()->id;
                }
            }
            $memo[$key] = $conversation_id;
            request()->attributes->set('folder_conversation_id', $memo);
        }

        return $memo[$key];
    }

    public function folderList($folder, $user, $page = null)
    {
        $memo = request()->attributes->get('folder_list', []);
        $key = $folder->id.'-'.$user->id.'-'.(int) $page;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if ($folder->id < 0) {
            $query = AllMailboxes::query($folder, $user);
            $mailbox = AllMailboxes::mailbox();
            $params = ['show_mailbox' => true];
        } else {
            $query = Conversation::getQueryByFolder($folder, $user->id);
            $mailbox = $folder->mailbox;
            $params = [];
        }

        $memo[$key] = [
            'conversations' => $folder->queryAddOrderBy($query)->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $page),
            'mailbox'       => $mailbox,
            'params'        => $params,
        ];
        request()->attributes->set('folder_list', $memo);

        return $memo[$key];
    }

    public function listConversations($input, $user, $context = null)
    {
        if ($context) {
            $request = $context;
        } else {
            $request = Request::createFrom(request());
            $request->query->replace([]);
            $request->setJson(new InputBag());
            $request->replace($input);
        }

        return $this->query($request, $user);
    }

    private function query(Request $request, $user)
    {
        if (!empty($request->filter)) {
            // Filter conversations by Assigned To column in Search.
            if (!empty($request->params['user_id']) && !empty($request->filter['f'])) {
                $filter = $request->filter ?? [];
                $filter['f']['assigned'] = (int)$request->params['user_id'];

                $request->merge(['filter' => $filter]);
            }

            if (array_key_exists('q', $request->filter)) {
                // Search.
                $conversations = $this->searchQuery($user, $this->getSearchQuery($request), $this->getSearchFilters($request), $request);
            } else {
                // Filters in the mailbox or customer profile.
                $conversations = $this->conversationsFilterQuery($request, $user);
            }

            return [
                'folder'               => null,
                'conversations'        => $conversations,
                'conversations_filter' => $request->filter['f'] ?? $request->filter ?? [],
            ];
        }

        $folder = \Eventy::filter('conversations.ajax_pagination_folder', Folder::find($request->folder_id), $request, ['status' => 'error', 'msg' => ''], $user);
        if (!$folder) {
            return ['msg' => __('Folder not found')];
        }
        // We should not use mailbox_id from the request, as it can be changed.
        if (!$user->can('view', $folder) || !$user->can('view', $folder->mailbox)) {
            return ['msg' => __('Not enough permissions')];
        }

        $query_conversations = Conversation::getQueryByFolder($folder, $user->id);

        if (!empty($request->params['user_id'])) {
            $query_conversations->where('conversations.user_id', (int)$request->params['user_id']);
        }

        return [
            'folder'               => $folder,
            'conversations'        => $folder->queryAddOrderBy($query_conversations)->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $request->page),
            'conversations_filter' => [],
        ];
    }

    public function getSearchMode($request)
    {
        $mode = Conversation::SEARCH_MODE_CONV;
        if (!empty($request->mode) && $request->mode == Conversation::SEARCH_MODE_CUSTOMERS) {
            $mode = Conversation::SEARCH_MODE_CUSTOMERS;
        }
        return $mode;
    }

    public function searchQuery($user, $q, $filters, $request = null)
    {
        $request = $request ?: request();
        $conversations = \Eventy::filter('search.conversations.perform', '', $q, $filters, $user);
        if ($conversations !== '') {
            return $conversations;
        }

        if ($user->canSeeOnlyAssignedConversations()) {
            $filters['assigned'] = $user->id;
        }

        if (\App\Search\ConversationSearch::available()) {
            // Best matches first, unless sorted in the list.
            if (empty($request->sorting) && \App\Search\SearchQuery::parse($q)->hasTerms()) {
                $request->merge(['sorting' => ['sort_by' => 'relevance', 'order' => 'desc']]);
            }

            return \App\Search\ConversationSearch::paginate($q, $filters, $user, Conversation::DEFAULT_LIST_SIZE, $request);
        }

        $query_conversations = Conversation::search($q, $filters, $user, null, [], $request);
        return $query_conversations->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $request->page);
    }

    public function getSearchQuery($request)
    {
        $q = '';
        if (!empty($request->q)) {
            $q = $request->q;
        } elseif (!empty($request->filter) && !empty($request->filter['q'])) {
            $q = $request->filter['q'];
        }

        return trim($q);
    }

    public function getSearchFilters($request)
    {
        $filters = [];

        if (!empty($request->f)) {
            $filters = $request->f;
        } elseif (!empty($request->filter) && !empty($request->filter['f'])) {
            $filters = $request->filter['f'];
        }

        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'after':
                case 'before':
                    if ($value) {
                        $filters[$filter] = date('Y-m-d', strtotime($value));
                    }
                    break;
                case 'status':
                case 'state':
                    if (!is_array($value)) {
                        unset($filters[$filter]);
                    }
                    break;
            }
        }

        $filters = \Eventy::filter('search.filters', $filters, $this->getSearchMode($request), $request);

        return $filters;
    }

    public function conversationsFilterQuery($request, $user)
    {
        // Get IDs of mailboxes to which user has access
        $mailbox_ids = $user->mailboxesIdsCanView();

        $query_conversations = Conversation::whereIn('conversations.mailbox_id', $mailbox_ids)
            ->orderBy('conversations.last_reply_at');

        foreach ($request->filter as $field => $value) {
            switch ($field) {
                case 'customer_id':
                    $query_conversations->where('customer_id', $value);
                    break;
            }
        }

        if ($user->canSeeOnlyAssignedConversations()) {
            $query_conversations->where('conversations.user_id', $user->id);
        }

        if (!empty($request->params['user_id'])) {
            $query_conversations->where('conversations.user_id', (int)$request->params['user_id']);
        }

        return $query_conversations->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $request->page);
    }
}

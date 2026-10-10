<?php

namespace App\Search;

use App\Conversation;
use App\Thread;

/**
 * Conversation search on the full-text index (conversation_search): every
 * word must be somewhere in the conversation, best matches first, with
 * an excerpt around the words found.
 */
class ConversationSearch
{
    /**
     * The index's columns, by field (null: any).
     */
    const COLUMNS = [
        ''           => ['subject', 'people', 'recipients', 'content'],
        'subject'    => ['subject'],
        'people'     => ['people'],
        'recipients' => ['recipients'],
    ];

    /**
     * Words found in the subject count this much more.
     */
    const SUBJECT_WEIGHT = 3;

    /**
     * Excerpt length (characters).
     */
    const SNIPPET_LENGTH = 220;

    protected static $min_token_size = null;

    /**
     * Whether searches use the index.
     */
    public static function available()
    {
        return Indexer::isReady();
    }

    /**
     * The query for a search: conversations the user can see.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query($q, $filters, $user, ?SearchQuery $search = null, $request = null)
    {
        $search = $search ?: SearchQuery::parse($q);
        $t = Indexer::TABLE;

        $query = Conversation::select('conversations.*')
            ->join($t, $t.'.conversation_id', '=', 'conversations.id');

        if (!empty($filters['mailbox']) && $user->hasAccessToMailbox($filters['mailbox'])) {
            $query->where('conversations.mailbox_id', $filters['mailbox']);
        } else {
            unset($filters['mailbox']);
            $query->whereIn('conversations.mailbox_id', $user->mailboxesIdsCanView());
        }

        $score = self::addTerms($query, $search, $filters);
        self::addConditions($query, $search, $user);
        self::addFilters($query, $filters);

        $query = \Eventy::filter('search.conversations.apply_filters', $query, $filters, $q);

        $sorting = Conversation::getConvTableSorting($request);
        if ($sorting['sort_by'] == 'relevance' && $search->number() !== null) {
            $query->orderByRaw(\DB::getTablePrefix().'conversations.'.Conversation::numberFieldName().' = ? DESC', [$search->number()]);
        }
        if ($sorting['sort_by'] == 'relevance' && $score) {
            $query->orderByRaw($score[0].' DESC', $score[1]);
            $query->orderBy('conversations.last_reply_at', 'desc');
        } else {
            $sort_by = in_array($sorting['sort_by'], ['date', 'relevance']) ? 'last_reply_at' : $sorting['sort_by'];
            $query->orderBy('conversations.'.$sort_by, $sorting['order']);
        }

        return $query;
    }

    /**
     * A page of results, each with its excerpt (search_snippet).
     */
    public static function paginate($q, $filters, $user, $per_page = Conversation::DEFAULT_LIST_SIZE, $request = null)
    {
        $search = SearchQuery::parse($q);
        $conversations = self::query($q, $filters, $user, $search, $request)->paginate($per_page, ['*'], 'page', $request?->page);
        self::addSnippets($conversations, $search);

        return $conversations;
    }

    /**
     * Add the words to look for. Returns the relevance expression and its
     * bindings, or null when nothing is matched in the full-text index.
     */
    protected static function addTerms($query, SearchQuery $search, $filters)
    {
        $t = \DB::getTablePrefix().Indexer::TABLE;
        $min_length = self::minTokenSize();

        // Terms for the full-text index, by field; the others are looked up with LIKE.
        $boolean = [];
        $like = [];
        foreach ($search->terms as $term) {
            if (SearchQuery::isIndexable($term['text'], $min_length) && (Indexer::driver() != 'pgsql' || SearchQuery::words($term['text']))) {
                $boolean[(string) $term['field']][] = $term;
            } else {
                $like[] = $term;
            }
        }

        $score = null;
        $conditions = function ($where) use ($boolean, $like, $t, &$score) {
            foreach ($boolean as $field => $terms) {
                $match = self::matchSql($t, $field);
                $positive = array_filter($terms, function ($term) {
                    return !$term['exclude'];
                });
                if ($positive) {
                    $where->whereRaw($match, [self::fullTextQuery($terms, $field)]);
                    if ($field === '') {
                        $score = Indexer::driver() == 'mysql'
                            ? [
                                '(MATCH ('.$t.'.subject) AGAINST (? IN BOOLEAN MODE) * '.self::SUBJECT_WEIGHT.' + MATCH ('.self::columns($t, $field).') AGAINST (? IN BOOLEAN MODE))',
                                [self::fullTextQuery($positive, $field), self::fullTextQuery($positive, $field)],
                            ]
                            : [self::scoreSql($t), [self::fullTextQuery($positive, $field)]];
                    } elseif (!$score) {
                        $score = Indexer::driver() == 'mysql'
                            ? ['MATCH ('.self::columns($t, $field).') AGAINST (? IN BOOLEAN MODE)', [self::fullTextQuery($terms, $field)]]
                            : [self::scoreSql($t), [self::fullTextQuery($positive, $field)]];
                    }
                } else {
                    foreach ($terms as $term) {
                        // The words exactly, as a phrase.
                        $where->whereRaw('NOT ('.$match.')', [self::fullTextQuery([['exclude' => false, 'phrase' => true] + $term], $field)]);
                    }
                }
            }
            foreach ($like as $term) {
                $columns = self::COLUMNS[(string) $term['field']];
                $method = $term['exclude'] ? 'whereNot' : 'where';
                if (preg_match(SearchQuery::NO_SPACES, $term['text']) || Indexer::driver() != 'mysql') {
                    // Chinese, Japanese... anywhere (and what PostgreSQL and SQLite don't index).
                    $pattern = '%'.\App\Job::likeEscape(mb_strtolower($term['text'])).'%';
                    $where->$method(function ($any) use ($columns, $pattern) {
                        foreach ($columns as $column) {
                            $any->orWhereRaw(\DB::getTablePrefix().Indexer::TABLE.'.'.$column.' '.\Helper::sqlLikeOperator()." ? escape '!'", [$pattern]);
                        }
                    });
                } else {
                    // Short words from the start of a word.
                    $pattern = '(?<![[:alnum:]_])'.implode('\\s+', array_map('preg_quote', explode(' ', $term['text'])));
                    $where->$method(function ($any) use ($columns, $pattern) {
                        foreach ($columns as $column) {
                            $any->orWhere(Indexer::TABLE.'.'.$column, 'regexp', $pattern);
                        }
                    });
                }
            }
        };

        if (!$search->terms) {
            return null;
        }

        $number = $search->number();
        $or_where = (bool) \Eventy::getFilter()->getListeners('search.conversations.or_where');
        if ($number === null && !$or_where) {
            $conditions($query);

            return $score;
        }

        $query->where(function ($where) use ($conditions, $number, $or_where, $search, $filters) {
            $where->where($conditions);
            if ($number !== null) {
                $where->orWhere('conversations.'.Conversation::numberFieldName(), $number);
            }
            if ($or_where) {
                // Modules add matches with the joins of the search before the index.
                $where->orWhereIn('conversations.id', function ($legacy) use ($search, $filters) {
                    $legacy->select('conversations.id')->from('conversations')
                        ->join('threads', 'conversations.id', '=', 'threads.conversation_id')
                        ->leftJoin('customers', 'conversations.customer_id', '=', 'customers.id')
                        ->where(function ($group) use ($search, $filters) {
                            $group->whereRaw('0 = 1');
                            \Eventy::filter('search.conversations.or_where', $group, $filters, $search->q);
                        });
                });
            }
        });

        return $score;
    }

    /**
     * SQL that is true for rows matching the full-text query (one binding)
     * in the field's columns.
     */
    protected static function matchSql($t, $field)
    {
        switch (Indexer::driver()) {
            case 'pgsql':
                return $t.".search_vector @@ to_tsquery('simple', CAST(? AS text))";
            case 'sqlite':
                $fts = \DB::getTablePrefix().Indexer::FTS_TABLE;

                return $t.'.conversation_id IN (SELECT rowid FROM '.$fts.' WHERE '.$fts.' MATCH ?)';
            default:
                return 'MATCH ('.self::columns($t, $field).') AGAINST (? IN BOOLEAN MODE)';
        }
    }

    /**
     * PostgreSQL and SQLite: a row's relevance for a full-text query (one
     * binding), the subject counting more.
     */
    protected static function scoreSql($t)
    {
        if (Indexer::driver() == 'pgsql') {
            // Weights of D (content), C (recipients), B (people) and A (subject), as on MariaDB.
            return "ts_rank('{0.1, 0.1, 0.1, ".(0.1 * (self::SUBJECT_WEIGHT + 1))."}', ".$t.".search_vector, to_tsquery('simple', CAST(? AS text)))";
        }
        $fts = \DB::getTablePrefix().Indexer::FTS_TABLE;

        // bm25() is lower for better matches; weights of subject, people, recipients and content.
        return '(SELECT -bm25('.$fts.', '.(self::SUBJECT_WEIGHT + 1).', 1, 1, 1) FROM '.$fts.' WHERE '.$fts.' MATCH ? AND rowid = '.$t.'.conversation_id)';
    }

    /**
     * Terms as a full-text query in the database's syntax, in the field's columns.
     */
    protected static function fullTextQuery($terms, $field)
    {
        switch (Indexer::driver()) {
            case 'pgsql':
                $weight = Indexer::WEIGHTS[$field] ?? '';

                return implode(' & ', array_map(function ($term) use ($weight) {
                    return SearchQuery::tsqueryTerm($term, $weight);
                }, $terms));
            case 'sqlite':
                return SearchQuery::fts5Query($terms, $field);
            default:
                return implode(' ', array_map([SearchQuery::class, 'booleanTerm'], $terms));
        }
    }

    protected static function columns($table, $field)
    {
        return implode(', ', array_map(function ($column) use ($table) {
            return $table.'.'.$column;
        }, self::COLUMNS[$field]));
    }

    /**
     * mailbox:, is:, has:, before: and after:.
     */
    protected static function addConditions($query, SearchQuery $search, $user)
    {
        foreach ($search->conditions as $operator => $values) {
            foreach ($values as $value) {
                $value = mb_strtolower($value);
                switch ($operator) {
                    case 'mailbox':
                        $ids = $user->mailboxesCanView()->filter(function ($mailbox) use ($value) {
                            return str_contains(mb_strtolower($mailbox->name), $value) || str_contains(mb_strtolower($mailbox->email), $value);
                        })->pluck('id')->all();
                        $query->whereIn('conversations.mailbox_id', $ids ?: [0]);
                        break;

                    case 'is':
                        $statuses = [
                            'active'  => Conversation::STATUS_ACTIVE,
                            'open'    => Conversation::STATUS_ACTIVE,
                            'pending' => Conversation::STATUS_PENDING,
                            'closed'  => Conversation::STATUS_CLOSED,
                            'spam'    => Conversation::STATUS_SPAM,
                        ];
                        if (isset($statuses[$value])) {
                            Conversation::addStatusCondition($query, [$statuses[$value]]);
                        } elseif ($value == 'unassigned') {
                            $query->whereNull('conversations.user_id');
                        } elseif ($value == 'mine') {
                            $query->where('conversations.user_id', $user->id);
                        } elseif ($value == 'assigned') {
                            $query->whereNotNull('conversations.user_id');
                        } elseif ($value == 'following') {
                            $query->whereIn('conversations.id', \App\Follower::where('user_id', $user->id)->select('conversation_id'));
                        }
                        break;

                    case 'attachment':
                        $query->whereIn('conversations.id', self::withAttachmentNamed($value));
                        break;

                    case 'has':
                        if (in_array($value, ['attachment', 'attachments', 'file', 'files'])) {
                            $query->where('conversations.has_attachments', true);
                        }
                        break;

                    case 'before':
                    case 'after':
                        $time = strtotime($value);
                        if ($time) {
                            $operator == 'after'
                                ? $query->where('conversations.created_at', '>=', date('Y-m-d 00:00:00', $time))
                                : $query->where('conversations.created_at', '<=', date('Y-m-d 23:59:59', $time));
                        }
                        break;
                }
            }
        }
    }

    /**
     * The search form's filters (as the search before the index).
     */
    protected static function addFilters($query, $filters)
    {
        if (!empty($filters['assigned'])) {
            $query->where('conversations.user_id', $filters['assigned'] == Conversation::USER_UNASSIGNED ? null : $filters['assigned']);
        }
        if (!empty($filters['customer'])) {
            $customer_id = $filters['customer'];
            $query->where(function ($query) use ($customer_id) {
                $query->where('conversations.customer_id', $customer_id)
                    ->orWhereIn('conversations.id', Thread::where('created_by_customer_id', $customer_id)->select('conversation_id'));
            });
        }
        if (!empty($filters['status']) && is_array($filters['status'])) {
            Conversation::addStatusCondition($query, $filters['status']);
        }
        if (!empty($filters['state']) && is_array($filters['state'])) {
            $query->whereIn('conversations.state', $filters['state']);
        }
        if (!empty($filters['subject'])) {
            $query->where('conversations.subject', \Helper::sqlLikeOperator(), '%'.mb_strtolower($filters['subject']).'%');
        }
        if (!empty($filters['attachments'])) {
            $query->where('conversations.has_attachments', $filters['attachments'] == 'yes');
        }
        if (!empty($filters['type'])) {
            $query->where('conversations.type', $filters['type']);
        }
        if (!empty($filters['attachment name'])) {
            $query->whereIn('conversations.id', self::withAttachmentNamed($filters['attachment name']));
        }
        if (!empty($filters['body'])) {
            $query->whereRaw(\DB::getTablePrefix().Indexer::TABLE.'.content '.\Helper::sqlLikeOperator()." ? escape '!'", ['%'.\App\Job::likeEscape(mb_strtolower($filters['body'])).'%']);
        }
        if (!empty($filters['number'])) {
            $query->where('conversations.'.Conversation::numberFieldName(), $filters['number']);
        }
        if (!empty($filters['following']) && $filters['following'] == 'yes') {
            $query->whereIn('conversations.id', \App\Follower::where('user_id', auth()->user()->id)->select('conversation_id'));
        }
        if (!empty($filters['id'])) {
            $query->where('conversations.id', $filters['id']);
        }
        if (!empty($filters['after'])) {
            $query->where('conversations.created_at', '>=', date('Y-m-d 00:00:00', strtotime($filters['after'])));
        }
        if (!empty($filters['before'])) {
            $query->where('conversations.created_at', '<=', date('Y-m-d 23:59:59', strtotime($filters['before'])));
        }
    }

    /**
     * An excerpt of each result around the first word found, with the words marked.
     */
    public static function addSnippets($conversations, SearchQuery $search)
    {
        $terms = array_filter($search->terms, function ($term) {
            return !$term['exclude'] && !$term['field'];
        });
        if (!$terms || !count($conversations)) {
            return;
        }
        $contents = \DB::table(Indexer::TABLE)->whereIn('conversation_id', collect($conversations->items())->pluck('id'))->pluck('content', 'conversation_id');
        foreach ($conversations as $conversation) {
            $conversation->search_snippet = self::snippet((string) ($contents[$conversation->id] ?? ''), $terms);
        }
    }

    /**
     * HTML: an excerpt of $text around the first of the terms, escaped,
     * with the terms in <mark>. Null when none of them is in $text.
     */
    public static function snippet($text, $terms)
    {
        $pattern = self::pattern($terms);
        if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $first = mb_strlen(substr($text, 0, $match[0][1]));

        $start = max(0, $first - 60);
        if ($start > 0) {
            // Start at a word.
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < $first ? $space + 1 : $start;
        }
        $excerpt = mb_substr($text, $start, self::SNIPPET_LENGTH);
        $more = $start + self::SNIPPET_LENGTH < mb_strlen($text);
        if ($more) {
            $space = mb_strrpos($excerpt, ' ');
            $excerpt = $space > self::SNIPPET_LENGTH / 2 ? mb_substr($excerpt, 0, $space) : $excerpt;
        }

        $html = '';
        foreach (preg_split($pattern, $excerpt, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            $html .= $i % 2 ? '<mark>'.e($part).'</mark>' : e($part);
        }

        return ($start > 0 ? '…' : '').$html.($more ? '…' : '');
    }

    /**
     * A regular expression finding the terms as the index does: words
     * from their start, a word on its own also where it continues.
     */
    protected static function pattern($terms)
    {
        $parts = [];
        foreach ($terms as $term) {
            $part = implode('\s+', array_map(function ($word) {
                return preg_quote($word, '/');
            }, explode(' ', $term['text'])));
            if (preg_match('/^[\p{L}\p{N}_]/u', $term['text']) && SearchQuery::isIndexable($term['text'], 1)) {
                $part = '(?<![\p{L}\p{N}_])'.$part;
                if (!$term['phrase'] && count(SearchQuery::tokens($term['text'])) == 1) {
                    $part .= '(?:(?!'.substr(SearchQuery::NO_SPACES, 1, -2).')[\p{L}\p{N}_])*';
                }
            }
            $parts[] = $part;
        }
        usort($parts, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return '/('.implode('|', $parts).')/iu';
    }

    /**
     * Conversation IDs with an attachment whose name contains $name.
     */
    public static function withAttachmentNamed($name)
    {
        return Thread::join('attachments', 'attachments.thread_id', '=', 'threads.id')
            // Case-insensitive everywhere (ilike on PostgreSQL), with an escape character every database honours.
            ->whereRaw(\DB::getTablePrefix().'attachments.file_name '.\Helper::sqlLikeOperator()." ? escape '!'", ['%'.\App\Job::likeEscape(trim((string) $name)).'%'])
            ->select('threads.conversation_id');
    }

    /**
     * innodb_ft_min_token_size: shorter words aren't in the index.
     */
    protected static function minTokenSize()
    {
        if (Indexer::driver() != 'mysql') {
            // PostgreSQL and SQLite index words of any length.
            return 1;
        }
        if (self::$min_token_size === null) {
            try {
                self::$min_token_size = (int) (\DB::selectOne("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'")->Value ?? 3) ?: 3;
            } catch (\Throwable $e) {
                self::$min_token_size = 3;
            }
        }

        return self::$min_token_size;
    }
}

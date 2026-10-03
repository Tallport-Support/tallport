<?php

namespace App\Search;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Email;
use App\Thread;
use App\User;

/**
 * Keeps conversation_search up to date: a conversation that changes is
 * indexed again when the request, command or job is done, and
 * tallport:search-index indexes the rest (new installations, missed
 * changes) in the background.
 */
class Indexer
{
    const TABLE = 'conversation_search';

    /**
     * Largest text kept per conversation (bytes).
     */
    const CONTENT_LIMIT = 4 * 1024 * 1024;

    /**
     * Option set once every conversation has been indexed.
     */
    const READY_OPTION = 'search_index_ready';

    /**
     * Conversations to index when the request, command or job is done.
     */
    protected static $pending = [];

    /**
     * Index again what model changes affect.
     */
    public static function listen()
    {
        Thread::saved(function ($thread) {
            if ($thread->wasRecentlyCreated || $thread->wasChanged(['body', 'state', 'type', 'from', 'to', 'cc', 'bcc', 'conversation_id', 'created_by_customer_id', 'created_by_user_id'])) {
                self::touch($thread->conversation_id);
            }
        });
        Thread::deleted(function ($thread) {
            self::touch($thread->conversation_id);
        });
        Conversation::saved(function ($conversation) {
            if ($conversation->wasRecentlyCreated || $conversation->wasChanged(['subject', 'customer_id', 'customer_email'])) {
                self::touch($conversation->id);
            }
        });
        Conversation::deleted(function ($conversation) {
            try {
                \DB::table(self::TABLE)->where('conversation_id', $conversation->id)->delete();
            } catch (\Throwable $e) {
                // Before the migration.
            }
        });
        Customer::saved(function ($customer) {
            if ($customer->wasChanged(['first_name', 'last_name', 'company', 'phones', 'social_profiles'])) {
                self::touchCustomer($customer->id);
            }
        });
        foreach ([Email::class, \App\Nostr\CustomerKey::class] as $class) {
            $class::saved(function ($model) {
                self::touchCustomer($model->customer_id);
            });
            $class::deleted(function ($model) {
                self::touchCustomer($model->customer_id);
            });
        }
        foreach (['saved', 'deleted'] as $event) {
            Attachment::$event(function ($attachment) {
                if ($attachment->thread_id) {
                    self::touch(Thread::where('id', $attachment->thread_id)->value('conversation_id'));
                }
            });
        }
    }

    /**
     * Index a conversation again when the request, command or job is done
     * (or by tallport:search-index, in a long-running process).
     */
    public static function touch($conversation_id)
    {
        if (!$conversation_id || isset(self::$pending[$conversation_id])) {
            return;
        }
        self::$pending[$conversation_id] = true;
        try {
            \DB::table(self::TABLE)->where('conversation_id', $conversation_id)->update(['indexed_at' => null]);
        } catch (\Throwable $e) {
            // Before the migration.
            return;
        }
        \Illuminate\Support\defer(function () {
            self::flush();
        }, 'tallport-search-index')->always();
    }

    /**
     * Index a customer's conversations again (in the background).
     */
    public static function touchCustomer($customer_id)
    {
        if (!$customer_id) {
            return;
        }
        try {
            \DB::table(self::TABLE)
                ->whereIn('conversation_id', Conversation::where('customer_id', $customer_id)->select('id'))
                ->update(['indexed_at' => null]);
        } catch (\Throwable $e) {
            // Before the migration.
        }
    }

    /**
     * Index the conversations touched so far.
     */
    public static function flush()
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];
        if (!$ids) {
            return;
        }
        try {
            self::index($ids);
        } catch (\Throwable $e) {
            // tallport:search-index tries again.
            \Helper::logException($e, '[search index]');
        }
    }

    /**
     * Index conversations (removing the ones that no longer exist).
     */
    public static function index($conversation_ids)
    {
        $conversation_ids = array_values(array_unique(array_filter((array) $conversation_ids)));
        if (!$conversation_ids) {
            return 0;
        }
        $conversations = Conversation::whereIn('id', $conversation_ids)->with('customer')->get();

        $rows = [];
        foreach ($conversations as $conversation) {
            $rows[] = ['conversation_id' => $conversation->id] + self::document($conversation) + ['indexed_at' => now()];
        }
        if ($rows) {
            \DB::table(self::TABLE)->upsert($rows, ['conversation_id'], ['subject', 'people', 'recipients', 'content', 'indexed_at']);
        }
        $missing = array_diff($conversation_ids, $conversations->pluck('id')->all());
        if ($missing) {
            \DB::table(self::TABLE)->whereIn('conversation_id', $missing)->delete();
        }

        return count($rows);
    }

    /**
     * A conversation's searchable text: subject, people (who wrote),
     * recipients and content (messages, notes and attachment names).
     */
    public static function document(Conversation $conversation)
    {
        $threads = Thread::where('conversation_id', $conversation->id)
            ->where('type', '!=', Thread::TYPE_LINEITEM)
            ->where('state', '!=', Thread::STATE_DRAFT)
            ->orderBy('id')
            ->get();

        $people = [$conversation->customer_email];
        $customer_ids = array_filter(array_merge([$conversation->customer_id], $threads->pluck('created_by_customer_id')->all()));
        foreach (Customer::whereIn('id', array_unique($customer_ids))->with('emails')->get() as $customer) {
            $people = array_merge($people, self::customerText($customer));
        }
        $user_ids = array_unique(array_filter($threads->pluck('created_by_user_id')->all()));
        foreach (User::whereIn('id', $user_ids)->get() as $user) {
            $people[] = $user->getFullName();
        }

        $recipients = [];
        $content = [];
        $attachments = Attachment::whereIn('thread_id', $threads->pluck('id'))->pluck('file_name', 'thread_id');
        foreach ($threads as $thread) {
            if ($thread->from) {
                $people[] = $thread->from;
            }
            $recipients = array_merge($recipients, $thread->getToArray(), $thread->getCcArray(), $thread->getBccArray());
            $content[] = self::htmlToText($thread->body);
        }
        $content = array_merge($content, $attachments->values()->all());

        return [
            'subject'    => (string) $conversation->subject,
            'people'     => self::join($people),
            'recipients' => self::join($recipients),
            'content'    => mb_strcut(self::join($content, "\n"), 0, self::CONTENT_LIMIT),
        ];
    }

    /**
     * What identifies a customer: name, company, addresses, phones, profiles.
     */
    protected static function customerText(Customer $customer)
    {
        $text = [$customer->first_name, $customer->last_name, $customer->company];
        $text = array_merge($text, $customer->emails->pluck('email')->all());
        foreach ($customer->getPhones() as $phone) {
            $text[] = $phone['value'] ?? '';
            $text[] = \Helper::phoneToNumeric($phone['value'] ?? '');
        }
        foreach ($customer->getSocialProfiles() as $profile) {
            $text[] = $profile['value'] ?? '';
        }
        try {
            foreach (\App\Nostr\CustomerKey::where('customer_id', $customer->id)->get() as $key) {
                $text[] = $key->label;
                $text[] = $key->getNpub();
            }
        } catch (\Throwable $e) {
            // Before the Nostr tables.
        }

        return $text;
    }

    /**
     * Plain text of a message: tags removed, entities decoded, one space
     * between words (so phrases match across lines).
     */
    public static function htmlToText($html)
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/td|/h\d|/blockquote)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    protected static function join($values, $separator = ' ')
    {
        $values = array_unique(array_filter(array_map(function ($value) {
            return trim((string) $value);
        }, $values), 'strlen'));

        return implode($separator, $values);
    }

    /**
     * Index conversations that aren't (new ones first), for up to $seconds.
     * Returns the number indexed.
     */
    public static function indexStale($seconds = 50, $chunk = 200)
    {
        $started = microtime(true);
        $count = 0;
        do {
            $ids = \DB::table(self::TABLE)->whereNull('indexed_at')->limit($chunk)->pluck('conversation_id')->all();
            if (count($ids) < $chunk) {
                $ids = array_merge($ids, self::unindexedQuery()->orderBy('conversations.id', 'desc')->limit($chunk - count($ids))->pluck('conversations.id')->all());
            }
            if (!$ids) {
                if (!\Option::get(self::READY_OPTION)) {
                    \Option::set(self::READY_OPTION, 1);
                }
                break;
            }
            $count += self::index($ids);
        } while (microtime(true) - $started < $seconds);

        return $count;
    }

    /**
     * Conversations without a row in the index.
     */
    protected static function unindexedQuery()
    {
        return Conversation::leftJoin(self::TABLE, self::TABLE.'.conversation_id', '=', 'conversations.id')
            ->whereNull(self::TABLE.'.conversation_id');
    }

    /**
     * Index everything again (in the background); search keeps working meanwhile.
     */
    public static function rebuild()
    {
        \DB::table(self::TABLE)->update(['indexed_at' => null]);
    }

    /**
     * Remove rows of conversations deleted without Eloquent.
     */
    public static function prune()
    {
        return \DB::table(self::TABLE)
            ->whereNotIn('conversation_id', Conversation::select('id'))
            ->delete();
    }

    /**
     * Whether searches use the index: it is complete, on MariaDB or MySQL.
     */
    public static function isReady()
    {
        return in_array(\DB::getDriverName(), ['mysql', 'mariadb']) && \Option::get(self::READY_OPTION);
    }

    /**
     * [indexed, total] conversations.
     */
    public static function progress()
    {
        $total = Conversation::count();

        return [$total - self::unindexedQuery()->count(), $total];
    }
}

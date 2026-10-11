<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an email was sent (its Date header, set by the sender), kept apart
 * from when it was received (threads.created_at): threads.sent_at, and the
 * conversation's last_sent_at for the list's Date Sent order (the latest
 * message's date sent, else its received time).
 *
 * Existing email threads get theirs from their stored headers, a chunk at a
 * time; run again after an error, it carries on.
 */
class AddDateSent extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('threads', 'sent_at')) {
            Schema::table('threads', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable();
            });
        }
        if (!Schema::hasColumn('conversations', 'last_sent_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->timestamp('last_sent_at')->nullable();
                $table->index(['mailbox_id', 'last_sent_at']);
            });
        }

        self::backfillThreads();
        self::backfillConversations();
    }

    public function down()
    {
        if (Schema::hasColumn('conversations', 'last_sent_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropIndex(['mailbox_id', 'last_sent_at']);
                $table->dropColumn('last_sent_at');
            });
        }
        if (Schema::hasColumn('threads', 'sent_at')) {
            Schema::table('threads', function (Blueprint $table) {
                $table->dropColumn('sent_at');
            });
        }
    }

    /**
     * Email threads' date sent, from the Date header in their headers.
     */
    public static function backfillThreads($chunk = 500)
    {
        DB::table('threads')
            ->select('id', 'headers', 'created_at')
            ->where('source_type', \App\Thread::SOURCE_TYPE_EMAIL)
            ->whereNotNull('headers')
            ->whereNull('sent_at')
            ->chunkById($chunk, function ($threads) {
                DB::transaction(function () use ($threads) {
                    foreach ($threads as $thread) {
                        $sent_at = self::dateSent($thread->headers, $thread->created_at);
                        if ($sent_at) {
                            DB::table('threads')->where('id', $thread->id)->update(['sent_at' => $sent_at]);
                        }
                    }
                });
            });
    }

    /**
     * Conversations' last date sent: their latest message's, else when it
     * was received (last_reply_at).
     */
    public static function backfillConversations($chunk = 1000)
    {
        $prefix = DB::getTablePrefix();
        $latest_sent_at = 'SELECT t.sent_at FROM '.$prefix.'threads t'
            .' WHERE t.conversation_id = '.$prefix.'conversations.id'
            .' AND t.type IN ('.\App\Thread::TYPE_CUSTOMER.', '.\App\Thread::TYPE_MESSAGE.')'
            .' AND t.state = '.\App\Thread::STATE_PUBLISHED
            .' ORDER BY t.created_at DESC, t.id DESC LIMIT 1';

        $max_id = (int) DB::table('conversations')->max('id');
        for ($from = 1; $from <= $max_id; $from += $chunk) {
            DB::table('conversations')
                ->whereBetween('id', [$from, $from + $chunk - 1])
                ->whereNull('last_sent_at')
                ->update(['last_sent_at' => DB::raw('COALESCE(('.$latest_sent_at.'), '.$prefix.'conversations.last_reply_at)')]);
        }
    }

    /**
     * The Date header as FetchEmails saves it: in the app's time zone, a
     * date in the future as the time received. Null without a valid one.
     */
    public static function dateSent($headers, $received_at)
    {
        $value = \App\Incoming\HeaderText::value($headers, 'Date');
        if ($value === null || $value === '') {
            return null;
        }
        static $config;
        $config = $config ?: \Webklex\PHPIMAP\Config::make([]);
        try {
            $date = (new \Webklex\PHPIMAP\Header('Date: '.$value."\r\n", $config))->get('date');
            if (!$date->count()) {
                return null;
            }
            $date = $date->toDate();
        } catch (\Throwable $e) {
            return null;
        }
        if (config('app.timezone')) {
            $date->setTimezone(config('app.timezone'));
        }
        $received_at = $received_at ? Carbon::parse($received_at) : now();
        if ($date->greaterThan($received_at)) {
            $date = $received_at;
        }

        return $date->format('Y-m-d H:i:s');
    }
}

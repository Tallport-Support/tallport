<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * The 2026_11_17 migration: existing email threads get their date sent from
 * their stored headers, and conversations their last date sent.
 */
class DateSentMigrationTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\AddDateSent::class)) {
            require database_path('migrations/2026_11_17_010101_add_date_sent.php');
        }
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    /**
     * A customer's email in a new conversation, as saved before the
     * migration (no date sent), with these headers.
     */
    protected function emailThread($headers)
    {
        $subject = 'Question '.uniqid();
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => $subject,
        ]));
        $conversation = Conversation::where('subject', $subject)->first();
        $thread = $conversation->threads()->first();
        DB::table('threads')->where('id', $thread->id)->update(['headers' => $headers, 'sent_at' => null]);
        DB::table('conversations')->where('id', $conversation->id)->update(['last_sent_at' => null]);

        return $thread;
    }

    protected function sentAt($thread)
    {
        return DB::table('threads')->where('id', $thread->id)->value('sent_at');
    }

    protected function lastSentAt($conversation_id)
    {
        return DB::table('conversations')->where('id', $conversation_id)->value('last_sent_at');
    }

    protected function appTime($utc)
    {
        return Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function testThreadsGetTheirDateSentFromTheirHeaders()
    {
        $offset = $this->emailThread("From: casey@customer.example.org\r\nDate: Mon, 05 Oct 2026 08:30:00 +0200 (CEST)\r\nSubject: Hello");
        $folded = $this->emailThread("Subject: Hello\r\nDate: Tue, 06 Oct 2026\r\n 09:15:00 +0000\r\nX-Mailer: Test");
        $future = $this->emailThread('Date: '.now()->addDays(5)->format('r'));
        $invalid = $this->emailThread("Date: not a date\r\nSubject: Hello");
        $missing = $this->emailThread("From: casey@customer.example.org\r\nSubject: Hello\r\nX-Original-Date: Mon, 05 Oct 2026 08:30:00 +0000");
        // Written here, not emailed: no date sent.
        $web = $this->emailThread('Date: Mon, 05 Oct 2026 08:30:00 +0000');
        DB::table('threads')->where('id', $web->id)->update(['source_type' => Thread::SOURCE_TYPE_WEB]);

        DB::enableQueryLog();
        \AddDateSent::backfillThreads(2);
        $chunks = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "threads"') || str_contains($query['query'], 'from `threads`'))->count();
        DB::disableQueryLog();

        $this->assertSame($this->appTime('2026-10-05 06:30:00'), $this->sentAt($offset));
        $this->assertSame($this->appTime('2026-10-06 09:15:00'), $this->sentAt($folded));
        $this->assertSame($future->created_at->format('Y-m-d H:i:s'), $this->sentAt($future), 'A date in the future is the time received.');
        $this->assertNull($this->sentAt($invalid));
        $this->assertNull($this->sentAt($missing));
        $this->assertNull($this->sentAt($web));
        $this->assertGreaterThanOrEqual(3, $chunks, 'Five email threads in chunks of two.');

        // Again: what's there is kept.
        DB::table('threads')->where('id', $offset->id)->update(['sent_at' => '2026-01-01 00:00:00']);
        (new \AddDateSent())->up();
        $this->assertSame('2026-01-01 00:00:00', $this->sentAt($offset));
        $this->assertSame($this->appTime('2026-10-06 09:15:00'), $this->sentAt($folded));
    }

    public function testConversationsGetTheirLastDateSent()
    {
        $dated = $this->emailThread('Date: Mon, 05 Oct 2026 08:30:00 +0000');
        $undated = $this->emailThread('Subject: Hello');
        $replied = $this->emailThread('Date: Mon, 05 Oct 2026 08:30:00 +0000');
        $this->sendReply($this->agent, [
            'mailbox_id' => $this->mailbox->id, 'conversation_id' => $replied->conversation_id, 'body' => '<p>Answer</p>',
        ]);
        $kept = $this->emailThread('Date: Mon, 05 Oct 2026 08:30:00 +0000');
        DB::table('conversations')->where('id', $kept->conversation_id)->update(['last_sent_at' => '2026-01-01 00:00:00']);
        DB::table('conversations')->whereIn('id', [$dated->conversation_id, $undated->conversation_id, $replied->conversation_id])->update(['last_sent_at' => null]);
        \AddDateSent::backfillThreads();

        \AddDateSent::backfillConversations(1);

        $this->assertSame($this->appTime('2026-10-05 08:30:00'), $this->lastSentAt($dated->conversation_id));
        // No date sent: when it was received.
        $last_reply_at = fn ($thread) => DB::table('conversations')->where('id', $thread->conversation_id)->value('last_reply_at');
        $this->assertSame($last_reply_at($undated), $this->lastSentAt($undated->conversation_id));
        // The latest message is the reply written here.
        $this->assertSame($last_reply_at($replied), $this->lastSentAt($replied->conversation_id));
        $this->assertSame('2026-01-01 00:00:00', $this->lastSentAt($kept->conversation_id));
    }
}

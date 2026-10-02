<?php

namespace Tests\Feature;

use App\Conversation;
use App\Incoming\RawSources;
use App\Thread;
use Illuminate\Filesystem\Filesystem;
use Tests\FeatureTestCase;

/**
 * tallport:receive (an email from a file or piped in by a mail server) and
 * the raw sources of incoming email kept for re-importing.
 */
class ReceiveMailTest extends FeatureTestCase
{
    protected $storage;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        mkdir($this->storage.'/app', 0777, true);
        $this->app->useStoragePath($this->storage);
        $this->mailbox = $this->createMailbox([$this->createUser()], ['email' => 'support@receive.example']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->storage);
        parent::tearDown();
    }

    protected function eml($to = 'support@receive.example', $message_id = 'receive-1@customer.example')
    {
        $file = $this->storage.'/'.uniqid().'.eml';
        file_put_contents($file, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example>', 'to' => $to,
            'subject' => 'Received directly', 'message_id' => $message_id, 'body' => 'Hello from a pipe',
        ]));

        return $file;
    }

    public function testReceiveFindsMailboxFromRecipients()
    {
        $this->artisan('tallport:receive', ['file' => $this->eml()])->assertExitCode(0);

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame('Received directly', $conversation->subject);
        $this->assertSame('casey@customer.example', $conversation->customer_email);
        $this->assertStringContainsString('Hello from a pipe', $conversation->threads()->first()->body);
    }

    public function testReceiveWithMailboxOption()
    {
        $this->artisan('tallport:receive', ['file' => $this->eml('someone@else.example'), '--mailbox' => (string) $this->mailbox->id])
            ->assertExitCode(0);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testReceiveWithoutMailbox()
    {
        $this->artisan('tallport:receive', ['file' => $this->eml('someone@else.example')])
            ->expectsOutputToContain('No mailbox among the recipients')
            ->assertExitCode(67);

        $this->artisan('tallport:receive', ['file' => $this->storage.'/missing.eml'])->assertExitCode(66);
    }

    public function testMailboxOptionAcceptsAnAlias()
    {
        $this->mailbox->aliases = 'help@receive.example';
        $this->mailbox->save();

        $this->artisan('tallport:receive', ['file' => $this->eml('someone@else.example'), '--mailbox' => 'Help@receive.example'])
            ->assertExitCode(0);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    /**
     * A mail server pipes an email once per recipient (--mailbox=${recipient}).
     */
    public function testDeliveryPerRecipient()
    {
        $sales = $this->createMailbox([$this->createUser()], ['email' => 'sales@receive.example']);
        $other = $this->createMailbox([$this->createUser()], ['email' => 'other@receive.example']);
        // To support and sales; other is in Bcc (only in the envelope).
        $file = $this->eml('support@receive.example, sales@receive.example');

        foreach (['support@receive.example', 'sales@receive.example', 'other@receive.example'] as $recipient) {
            $this->artisan('tallport:receive', ['file' => $file, '--mailbox' => $recipient])->assertExitCode(0);
        }

        foreach ([$this->mailbox, $sales, $other] as $mailbox) {
            $this->assertSame(1, Conversation::where('mailbox_id', $mailbox->id)->count(), $mailbox->email);
        }
    }

    public function testFailureAsksTheMailServerToTryAgain()
    {
        $fail = function ($data) {
            throw new \Exception('Database went away');
        };
        \Eventy::addFilter('fetch_emails.data_to_save', $fail, 20, 1);
        $file = $this->eml();

        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(75);
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());

        // The mail server delivers it again.
        \Eventy::removeFilter('fetch_emails.data_to_save', $fail, 20);
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testFailedEmailShowsWarningAndAlertsUntilReceived()
    {
        $admin = $this->createAdmin(['email' => 'boss@example.org']);
        \Option::set('alert_fetch', true);
        $fail = function ($data) {
            throw new \Exception('Database went away');
        };
        \Eventy::addFilter('fetch_emails.data_to_save', $fail, 20, 1);
        $file = $this->eml();
        $warning = 'Incoming email could not be saved and the mail server is trying again';

        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(75);
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(75);

        $this->assertCount(1, \App\Incoming\ReceiveFailures::all());
        $alerts = $this->sentEmailsTo('boss@example.org');
        $this->assertCount(1, $alerts, 'One alert, not one per attempt.');
        $this->assertStringContainsString('Receiving Problems', $alerts[0]->getSubject());
        $this->actingAs($admin)->get('/mailbox/'.$this->mailbox->id)->assertSee($warning);

        // The mail server delivers it again, and now it is saved.
        \Eventy::removeFilter('fetch_emails.data_to_save', $fail, 20);
        $this->captured_mail->flush();
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);

        $this->assertSame([], \App\Incoming\ReceiveFailures::all());
        $this->assertStringContainsString('Receiving Recovered', $this->sentEmailsTo('boss@example.org')[0]->getSubject());
        $this->actingAs($admin)->get('/mailbox/'.$this->mailbox->id)->assertDontSee($warning);
    }

    public function testOldFailuresExpire()
    {
        \Option::set(\App\Incoming\ReceiveFailures::OPTION, ['old' => time() - 8 * 86400, 'new' => time() - 3600]);

        $this->assertSame(['new'], array_keys(\App\Incoming\ReceiveFailures::all()));
    }

    public function testReceivingTwiceSavesOnce()
    {
        $file = $this->eml();

        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);

        $this->assertSame(1, Thread::where('message_id', 'receive-1@customer.example')->count());
    }

    public function testUnreadableEmailAsksTheMailServerToTryAgain()
    {
        $file = $this->storage.'/broken.eml';
        // Neither parser reads a multipart email without a boundary.
        file_put_contents($file, "From: casey@customer.example\r\nTo: support@receive.example\r\nSubject: Broken\r\nContent-Type: multipart/mixed\r\n\r\nHello");

        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(75);
    }

    public function testRawSourceIsKeptAndCleanedUp()
    {
        config(['app.incoming_mail_retention_days' => 30]);
        $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents($file = $this->eml())));

        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);

        $thread = Thread::where('message_id', 'receive-1@customer.example')->first();
        $this->assertSame($raw, file_get_contents(RawSources::path($thread)));

        // Older than the retention period: removed by tallport:clean-tmp.
        touch(RawSources::path($thread), time() - 31 * 86400);
        $this->assertSame(1, RawSources::clean());
        $this->assertFileDoesNotExist(RawSources::path($thread));
    }

    public function testNoRawSourceWhenRetentionIsOff()
    {
        config(['app.incoming_mail_retention_days' => 0]);

        $this->artisan('tallport:receive', ['file' => $this->eml()])->assertExitCode(0);

        $thread = Thread::where('message_id', 'receive-1@customer.example')->first();
        $this->assertFileDoesNotExist(RawSources::path($thread));
    }

    /**
     * Receiving the same email again doesn't duplicate it; after its
     * conversation is deleted for good it can be imported again.
     */
    public function testReceivingAgain()
    {
        $file = $this->eml();
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);

        $this->assertSame(1, Thread::where('message_id', 'receive-1@customer.example')->count());

        Conversation::where('mailbox_id', $this->mailbox->id)->first()->deleteForever();
        $this->artisan('tallport:receive', ['file' => $file])->assertExitCode(0);

        $this->assertSame(1, Thread::where('message_id', 'receive-1@customer.example')->count());
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }
}

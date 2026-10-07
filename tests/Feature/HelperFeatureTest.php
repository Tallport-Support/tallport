<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\Helper;
use App\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\FeatureTestCase;

/**
 * \Helper's functions that use the database, the session, the current
 * request or the queue.
 */
class HelperFeatureTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    /**
     * The current route, and the menu item it belongs to.
     */
    public function testCurrentRouteAndMenu()
    {
        $this->actingAs($this->createAdmin())->get(route('settings'))->assertOk();

        $this->assertTrue(Helper::isRoute('settings'));
        $this->assertTrue(Helper::isRoute(['system', 'settings']));
        $this->assertFalse(Helper::isRoute('system'));
        $this->assertFalse(Helper::isRoute(['system', 'logs']));

        $this->assertTrue(Helper::isMenuSelected('manage'));
        $this->assertTrue(Helper::isMenuSelected('settings'));
        $this->assertFalse(Helper::isMenuSelected('dashboard'));
        $this->assertFalse(Helper::isMenuSelected('mailbox'));
    }

    /**
     * Restarting the queue workers queues one RestartQueueWorker job (for
     * workers on another file system), unless one is waiting already.
     */
    public function testQueueWorkerRestart()
    {
        Queue::fake();
        \Cache::forget('illuminate:queue:restart');

        Helper::queueWorkerRestart();
        Queue::assertNothingPushed();
        $this->assertEqualsWithDelta(time(), \Cache::get('illuminate:queue:restart'), 5);

        \DB::table('jobs')->where('payload', 'like', '%RestartQueueWorker%')->delete();
        Helper::queueWorkerRestart();
        Queue::assertPushedOn('default', \App\Jobs\RestartQueueWorker::class);
    }

    /**
     * The language chosen in the session, when Tallport has it.
     */
    public function testSetUserLocaleFromTheSession()
    {
        session(['user_locale' => 'de']);
        Helper::setUserLocale();
        $this->assertSame('de', app()->getLocale());

        Helper::setUserLocale('xx');
        $this->assertSame('de', app()->getLocale(), 'Not a supported language.');

        Helper::setUserLocale('fr');
        $this->assertSame('fr', app()->getLocale());
    }

    /**
     * Uploaded SVG images lose their scripts; other types can be refused.
     */
    public function testUploadFile()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><!-- <script>alert(0)</script> --><script>alert(1)</script><circle r="5" onload="alert(2)"/></svg>';

        $path = Helper::uploadFile(UploadedFile::fake()->createWithContent('logo.svg', $svg), ['png', 'svg']);

        $name = basename($path);
        $this->assertSame(storage_path('uploads/'.$name), $path);
        $this->assertStringEndsWith('.svg', $name);
        $stored = \Storage::get('uploads/'.$name);
        $this->assertStringContainsString('<circle', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);

        try {
            Helper::uploadFile(UploadedFile::fake()->createWithContent('logo.svg', $svg), ['png'], ['image/png']);
            $this->fail('An SVG was accepted as a PNG.');
        } catch (\Exception $e) {
            $this->assertSame(Helper::EXCEPTION_NOT_ALLOWED_FILE_MIME_TYPE, $e->getCode());
        }
    }

    /**
     * An SVG that isn't well-formed XML still loses its script blocks.
     */
    public function testSanitizeBrokenSvg()
    {
        \Storage::put('uploads/broken.svg', '<svg><script>alert(1)</script><g>');

        Helper::sanitizeUploadedFileData('uploads/broken.svg');

        $this->assertSame('<svg><g>', \Storage::get('uploads/broken.svg'));

        \Storage::put('uploads/note.txt', '<script>alert(1)</script>');
        Helper::sanitizeUploadedFileData('uploads/note.txt');
        $this->assertSame('<script>alert(1)</script>', \Storage::get('uploads/note.txt'), 'Only SVG images.');
    }

    /**
     * Agents are warned when outgoing mail isn't being sent.
     */
    public function testSendingProblemsAlert()
    {
        $this->assertSame([], Helper::maybeShowSendingProblemsAlert());

        \Option::set('send_emails_problem', 1);
        $flashes = Helper::maybeShowSendingProblemsAlert();

        $this->assertCount(1, $flashes);
        $this->assertSame('warning', $flashes[0]['type']);
        $this->assertTrue($flashes[0]['unescaped']);
        $this->assertStringContainsString('<a href="'.route('system').'#cron" target="_blank">System Status</a>', $flashes[0]['text']);
        $this->assertStringContainsString('/wiki/Background-Jobs', $flashes[0]['text']);
    }

    /**
     * The user's time format, or the help desk's when nobody is logged in.
     */
    public function testTimeFormat()
    {
        $this->assertSame(User::TIME_FORMAT_24, (int) Helper::getTimeFormat());
        $this->assertTrue(Helper::isTimeFormat24());

        \Option::set('time_format', User::TIME_FORMAT_12);
        $this->assertFalse(Helper::isTimeFormat24());

        $this->actingAs($this->createUser(['time_format' => User::TIME_FORMAT_24]));
        $this->assertTrue(Helper::isTimeFormat24());
    }

    public function testDayName()
    {
        $this->assertSame('Today', Helper::dayName(now()));
        $this->assertSame('Yesterday', Helper::dayName(now()->subDay()));
        $this->assertSame('Sunday, January 5, 2020', Helper::dayName(\Carbon\Carbon::parse('2020-01-05 12:00:00')));
    }

    /**
     * A refusal the visitor may read is marked for the error page.
     */
    public function testDenyAccess()
    {
        foreach ([[['', false], 'This action is unauthorized.'], [['Untrusted host', true], 'Untrusted host[display]']] as [$arguments, $message]) {
            try {
                Helper::denyAccess(...$arguments);
                $this->fail('Not refused.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    /**
     * The last reply time may be given as a Carbon date.
     */
    public function testLastReplyAtFromACarbonDate()
    {
        $mailbox = $this->createMailbox();
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();

        $this->knownBug('C16');
        $conversation->setLastReplyAt(\Carbon\Carbon::parse('2024-05-01 10:00:00'), Conversation::PERSON_CUSTOMER);
        $this->assertSame('2024-05-01 10:00:00', $conversation->last_reply_at->format('Y-m-d H:i:s'));
    }
}

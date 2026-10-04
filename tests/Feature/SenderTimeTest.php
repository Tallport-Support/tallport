<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\SenderTime;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * A customer's local time, from the Date header of their emails.
 */
class SenderTimeTest extends FeatureTestCase
{
    public function testLocalTime()
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 3, 12, 0, 0, 'UTC'));
        $admin = $this->createAdmin(['timezone' => 'UTC']);
        $mailbox = $this->createMailbox([$admin]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $mailbox->email,
            'date' => 'Sat, 3 Oct 2026 09:14:00 -0500 (CDT)',
        ]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $thread = $conversation->threads()->first();

        $this->assertSame('-05:00', SenderTime::offset($conversation));
        $this->assertSame('09:14', SenderTime::format(SenderTime::sentAt($thread), '-05:00'));

        $this->actingAs($admin)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
            ->assertSeeInOrder(['Local time', '07:00 (GMT-05:00)'])
            ->assertSee('Sent at 09:14 their time (GMT-05:00)');

        // Not from an email (Telegram, Nostr, phone): nothing to say.
        $thread->headers = null;
        $thread->save();
        $this->assertNull(SenderTime::offset($conversation));
        Carbon::setTestNow();
    }
}

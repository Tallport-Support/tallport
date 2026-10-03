<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\Noreply;
use Tests\FeatureTestCase;

/**
 * No-reply addresses: recognised, warned about, and no auto replies.
 */
class NoreplyTest extends FeatureTestCase
{
    public function testAddresses()
    {
        foreach (['no-reply@example.com', 'NoReply@example.com', 'no_reply@example.com', 'support-noreply@example.com', 'do-not-reply@shop.example', 'auto-reply@example.com'] as $email) {
            $this->assertTrue(Noreply::isNoreply($email), $email);
        }
        foreach (['casey@example.com', 'reply@example.com', 'casey@no-reply.example.com', ''] as $email) {
            $this->assertFalse(Noreply::isNoreply($email), $email);
        }

        \Option::set(Noreply::OPTION, "notifications\nalerts*@monitor.example\n  \nBILLING@shop.example");
        $this->assertSame(['notifications', 'alerts*@monitor.example', 'billing@shop.example'], Noreply::customPatterns());
        $this->assertTrue(Noreply::isNoreply('team-notifications@example.com'));
        $this->assertTrue(Noreply::isNoreply('alerts-eu@monitor.example'));
        $this->assertFalse(Noreply::isNoreply('alerts@monitor.example.org'), 'A whole address matches the whole address.');
        $this->assertTrue(Noreply::isNoreply('billing@shop.example'));
        $this->assertFalse(Noreply::isNoreply('billing@shop.example.org'));
    }

    public function testNoAutoReplyToNoreplyAddresses()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $mailbox->auto_reply_enabled = true;
        $mailbox->auto_reply_subject = 'We got your message';
        $mailbox->auto_reply_message = 'We will answer within a day.';
        $mailbox->save();

        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Shop <no-reply@shop.example>', 'to' => $mailbox->email, 'subject' => 'Your order']));
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Question']));

        $this->assertCount(0, $this->sentEmailsTo('no-reply@shop.example'));
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testWarningsAndSettings()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Shop <no-reply@shop.example>', 'to' => $mailbox->email, 'subject' => 'Your order']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();

        $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
            ->assertSee('id="noreply-patterns"', false)
            ->assertSee(e(json_encode(Noreply::regexes())), false);

        // Mail Settings also write the environment file: a temporary one.
        $env_dir = sys_get_temp_dir().'/tallport-env-'.uniqid();
        mkdir($env_dir);
        file_put_contents($env_dir.'/.env.testing', "APP_TIMEZONE=UTC\n");
        $this->app->useEnvironmentPath($env_dir);
        $this->beforeApplicationDestroyed(function () use ($env_dir) {
            @unlink($env_dir.'/.env.testing');
            @rmdir($env_dir);
        });

        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('settings', ['section' => 'emails']))->assertOk()->assertSee('No-reply addresses');
        \Session::start();
        $this->post(route('settings.save', ['section' => 'emails']), ['_token' => csrf_token(), 'settings' => [
            'mail_from' => 'help@example.com', 'mail_driver' => 'mail', 'noreply_emails' => "alerts\nbounces",
        ]])->assertRedirect();
        \Option::$cache = [];
        $this->assertSame(['alerts', 'bounces'], Noreply::customPatterns());
    }
}

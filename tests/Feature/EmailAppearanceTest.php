<?php

namespace Tests\Feature;

use App\Conversation;
use App\Jobs\SendNotificationToUsers;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Every email Tallport sends looks like other emails: no fonts, sizes or text colours of its
 * own (the reader's mail app's apply, in light and dark appearance), no page backgrounds.
 * Muted lines (smaller, grey) and the hidden reply marker (0px) are the exceptions.
 */
class EmailAppearanceTest extends FeatureTestCase
{
    protected function assertLooksNative($html)
    {
        $this->assertDoesNotMatchRegularExpression('/font-family:(?!\s*Menlo)|font:\s|font-size:(?!\s*(0px|smaller))|line-height:(?!\s*0px)/', $html);
        $this->assertDoesNotMatchRegularExpression('/bgcolor=|background(-color)?:/', $html);
        // Text colours: only the muted grey, the hidden marker's white and the signature's grey.
        preg_match_all('/(?<![-\w])color:\s*(#[0-9a-f]+)/i', $html, $colors);
        $this->assertSame([], array_values(array_diff(array_map('strtolower', $colors[1]), ['#999999', '#ffffff', '#808080', '#b37100'])));
    }

    public function testNotificationToAgents()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Shop Support']);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey Customer <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Question about my order', 'body' => 'Where is my order?']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $response = $this->postAjax($agent, '/conversation/ajax', ['action' => 'send_reply', 'mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Checking with the warehouse</p>', 'is_note' => 1])->json();
        $this->assertSame('success', $response['status'], json_encode($response));
        $this->captured_mail->flush();

        $job = (new SendNotificationToUsers(collect([$agent]), $conversation->fresh(), $conversation->fresh()->getThreads()))->withFakeQueueInteractions();
        $job->handle();

        $html = $this->sentEmailsTo($agent->email)[0]->getBody();
        $this->assertLooksNative($html);
        $this->assertStringContainsString('Question about my order', $html);
        $this->assertMatchesRegularExpression('#border-left:3px solid \#e6b216;[^>]*>\s*<div>\s*<span>\s*<strong>[^<]+</strong> added a note#', $html, 'Notes are marked by a bar.');
        $this->assertStringContainsString('Checking with the warehouse', $html);
        $this->assertStringContainsString('Where is my order?', $html);
    }

    public function testSystemEmails()
    {
        $user = $this->createUser(['email' => 'agent@example.org']);
        $mailbox = $this->createMailbox([$user]);

        foreach ([
            new \App\Mail\UserInvite($user),
            new \App\Mail\PasswordChanged($user),
            new \App\Mail\Alert('Something happened', 'Alert'),
            new \App\Mail\UserEmailReplyError(),
            new \App\Mail\Test($mailbox),
            new \App\Mail\Test(),
        ] as $mail) {
            $this->assertLooksNative($mail->render());
        }
    }

    public function testPasswordResetEmail()
    {
        $this->createUser(['email' => 'agent@example.org']);

        \Session::start();
        $this->post('/password/email', ['_token' => csrf_token(), 'email' => 'agent@example.org']);

        $html = $this->sentEmailsTo('agent@example.org')[0]->getBody();
        $this->assertLooksNative($html);
        $this->assertMatchesRegularExpression('#<a href="[^"]+/password/reset/[^"]+" target="_blank"><strong>#', $html);
    }
}

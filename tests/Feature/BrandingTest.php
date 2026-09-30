<?php

namespace Tests\Feature;

use App\Conversation;
use App\Option;
use Tests\FeatureTestCase;

/**
 * Users see Tallport, with credit to FreeScout where the product is named.
 */
class BrandingTest extends FeatureTestCase
{
    public function testPagesShowTallportAndCreditFreeScout()
    {
        $response = $this->actingAs($this->createUser())->get('/');

        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('#<title>[^<]*Tallport\s*</title>#', $html);
        $this->assertStringContainsString('<a href="'.config('app.tallport_url').'" target="_blank">Tallport</a>, based on <a href="https://freescout.net" target="_blank">FreeScout</a>', $html);
        $this->assertStringContainsString('img/logo-brand.svg', $html);
    }

    public function testCustomerEmailsCreditTallport()
    {
        Option::set('email_branding', true);
        Option::$cache = [];
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();

        $this->postAjax($agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi</p>',
        ]);

        $body = $this->sentEmailsTo('casey@customer.example.org')[0]->getBody();
        $this->assertStringContainsString('Support powered by <a href="'.config('app.tallport_url').'"', $body);
        $this->assertStringContainsString('based on <a href="https://freescout.net"', $body);
        $this->assertStringNotContainsString('landing.freescout.net', $body);
    }

    public function testNewVersionLinksToTallportReleases()
    {
        // The status page caches the latest version under this key.
        \Cache::put('latest_version', '99.0.0', 15);

        $response = $this->actingAs($this->createAdmin())->get('/system/status');

        $response->assertSee('A new version is available');
        $response->assertSee('href="'.config('app.tallport_url').'/releases"', false);
        $this->assertStringNotContainsString('freescout.net/github/releases', $response->getContent());
    }

    public function testSystemEmailsAreFromTallport()
    {
        $admin = $this->createAdmin();

        $this->postAjax($admin, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'me@example.org']);

        $email = $this->sentEmailsTo('me@example.org')[0];
        $this->assertSame('Tallport Test Email', $email->getSubject());
        $this->assertStringContainsString('Tallport', $email->getBody());
    }
}

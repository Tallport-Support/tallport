<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\ExternalImages;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Images from other servers in customers' messages aren't loaded until an
 * agent shows them.
 */
class ExternalImagesTest extends FeatureTestCase
{
    public function testOriginalBodiesDoNotLoadBlockedRemoteImages()
    {
        $this->knownBug('C15');
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Remote image', 'html' => true,
            'body' => '<p>Hello <img src="https://tracker.example/p.gif"></p>',
        ]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $thread->body_original = $thread->body;
        $thread->saveQuietly();

        $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
            ->assertSee('Images from other servers are not shown.')
            ->assertDontSee('<img src="https://tracker.example/p.gif"', false);
    }

    public function testWhatIsBlocked()
    {
        $here = rtrim(config('app.url'), '/');
        [$html, $count] = ExternalImages::block(
            '<p><img src="https://tracker.example/p.gif" width="1" alt="x">'
            .'<img src="'.$here.'/storage/attachment/1/2/3/a.png?id=1&amp;token=t">'
            .'<img src="data:image/png;base64,AAAA"><img src="cid:logo"><img src="/img/logo.png">'
            .'<img src=http://unquoted.example/p.gif><img srcset="a.png 1x, //cdn.example/b.png 2x">'
            .'<table background="https://bg.example/x.png"><tr><td style="background:url(\'https://bg.example/y.png\')">x</td></tr></table>'
            .'<video poster="https://v.example/poster.jpg"></video></p>'
            .'<style>.a { background: url(https://css.example/z.png); } @import url("https://css.example/more.css");</style>'
        );

        $this->assertSame(8, $count);
        $this->assertStringContainsString('data-blocked-src="https://tracker.example/p.gif" width="1" alt="x"', $html);
        $this->assertStringContainsString('<img src="'.$here.'/storage/attachment/', $html);
        $this->assertStringContainsString('src="data:image/png', $html);
        $this->assertStringContainsString('src="cid:logo"', $html);
        $this->assertStringContainsString('src="/img/logo.png"', $html);
        $this->assertStringContainsString('data-blocked-src=http://unquoted.example', $html);
        $this->assertStringContainsString('data-blocked-srcset=', $html);
        $this->assertStringContainsString('data-blocked-background=', $html);
        $this->assertStringContainsString('data-blocked-poster=', $html);
        $this->assertStringNotContainsString('bg.example/y.png', $html);
        $this->assertStringNotContainsString('css.example', $html);
    }

    public function testShowingImages()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Newsletter', 'html' => true,
            'body' => '<p>Hi <img src="https://tracker.example/p.gif"></p>',
        ]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $page = $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk();
        $page->assertSee('Images from other servers are not shown.')->assertSee('data-blocked-src="https://tracker.example/p.gif"', false)
            ->assertDontSee('<img src="https://tracker.example/p.gif"', false);

        // This message.
        $response = $this->postAjax($agent, route('conversations.external_images'), ['action' => 'display', 'thread_id' => $thread->id]);
        $response->assertJsonPath('status', 'success');
        $this->assertStringContainsString('src="https://tracker.example/p.gif"', $response->json('html'));
        $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertDontSee('Images from other servers are not shown.');

        // Always for the customer, and hidden again.
        $this->postAjax($agent, route('conversations.external_images'), ['action' => 'display_customer', 'thread_id' => $thread->id])->assertJsonPath('reload', true);
        $this->assertSame(1, $conversation->customer->fresh()->getMeta(ExternalImages::META_KEY));
        // The models' query cache lasts for the request; this test makes several.
        \Cache::store('array')->flush();
        $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertSee('Hide images from other servers');
        $this->postAjax($agent, route('conversations.external_images'), ['action' => 'block_customer', 'customer_id' => $conversation->customer_id]);
        $this->assertSame(0, $conversation->customer->fresh()->getMeta(ExternalImages::META_KEY));

        // Not someone else's conversation.
        $other = $this->createUser();
        $this->postAjax($other, route('conversations.external_images'), ['action' => 'display', 'thread_id' => $thread->id])->assertJsonPath('status', 'error');
    }
}

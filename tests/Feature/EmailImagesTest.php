<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Misc\EmailImages;
use App\Option;
use Symfony\Component\Mime\Email;
use Tests\FeatureTestCase;

/**
 * Images in outgoing emails are never linked: Tallport's go inline, others are left out.
 */
class EmailImagesTest extends FeatureTestCase
{
    public function testImagesGoInlineOrAreLeftOut()
    {
        $attachment = Attachment::create('chart.png', 'image/png', Attachment::TYPE_IMAGE, 'PNGDATA', null, true);
        $url = $attachment->url();
        $email = (new Email())->to('ann@example.com')->subject('Hi')->html(
            '<p><img src="'.e($url).'" alt="Chart"> again <img alt="Chart" src=\''.$url.'\'>'
            .' <img src="https://tracker.example.com/pixel.gif" alt="Logo"> <img src="https://other.example.com/x.png">'
            .' <img src="'.e(str_replace('token=', 'token=x', $url)).'" alt="Forged">'
            .' <img src="data:image/png;base64,AAAA" alt="Data"></p>'
        );

        EmailImages::embed($email);

        $html = $email->getHtmlBody();
        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString('example.com', $html);
        $inline = array_values(array_filter($email->getAttachments(), fn ($part) => $part->getDisposition() == 'inline'));
        $this->assertCount(1, $inline, 'The same image goes in once.');
        $this->assertSame(2, substr_count($html, 'src="cid:'.$inline[0]->getContentId().'"'));
        $this->assertSame('PNGDATA', $inline[0]->getBody());
        // Others: their alt text only; data: images stay.
        $this->assertStringContainsString(' Logo  Forged <img src="data:image/png;base64,AAAA"', $html);
    }

    public function testOpenTrackingImageStaysWhenOn()
    {
        Option::set('open_tracking', true);
        $email = (new Email())->to('ann@example.com')->subject('Hi')->html('<img src="'.url('/thread/read/1/2/abc').'" alt="">');
        EmailImages::embed($email);
        $this->assertStringContainsString(url('/thread/read/1/2/abc'), $email->getHtmlBody());

        Option::set('open_tracking', false);
        \App\Option::$cache = [];
        $email = (new Email())->to('ann@example.com')->subject('Hi')->html('<img src="'.url('/thread/read/1/2/abc').'" alt="">');
        EmailImages::embed($email);
        $this->assertStringNotContainsString('/thread/read/', $email->getHtmlBody());
    }

    public function testRepliesToCustomersCarryImagesInline()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Screens']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $attachment = Attachment::create('screen.png', 'image/png', Attachment::TYPE_IMAGE, 'PNGDATA', null, true, null, $user->id);

        $this->sendReply($user, [
            'conversation_id' => $conversation->id, 'mailbox_id' => $mailbox->id,
            'body' => '<p>See <img src="'.e($attachment->url()).'" alt="screen"></p>',
            'attachments' => [encrypt($attachment->id)], 'attachments_all' => [encrypt($attachment->id)], 'embeds' => [encrypt($attachment->id)],
        ]);

        $sent = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('/storage/attachment/', $sent[0]->getBody());
        $parts = $sent[0]->message->getAttachments();
        $this->assertCount(1, $parts, 'Inline only, not attached a second time.');
        $this->assertSame('inline', $parts[0]->getDisposition());
    }
}

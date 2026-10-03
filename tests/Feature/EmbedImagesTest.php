<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Misc\EmbedImages;
use App\Thread;
use Symfony\Component\Mime\Email;
use Tests\FeatureTestCase;

/**
 * Images on this help desk go in outgoing emails themselves (cid:).
 */
class EmbedImagesTest extends FeatureTestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testWhichImages()
    {
        $image = Attachment::create('chart.png', 'image/png', null, base64_decode(self::PNG), null, true);
        $pdf = Attachment::create('terms.pdf', 'application/pdf', null, '%PDF', null);
        \Storage::disk('local')->put('uploads/logo.png', base64_decode(self::PNG));
        $logo = \Helper::uploadedFileUrl('logo.png');
        $forged = preg_replace('/token=[^&]+/', 'token=wrong', $image->url());

        $email = (new Email())->html(
            '<p><img src="'.e($image->url()).'" alt="chart"> <img src="'.e($image->url()).'"> <img src=\''.$logo.'\'>'
            .' <img src="data:image/png;base64,'.self::PNG.'"> <img src="https://other.example/pixel.png">'
            .' <img src="'.e($pdf->url()).'"> <img src="'.e($forged).'"></p>'
        );

        $this->assertSame(3, EmbedImages::embed($email), 'The chart once, the logo, the inline image.');
        $html = $email->getHtmlBody();
        $this->assertSame(4, substr_count($html, 'src="cid:') + substr_count($html, "src='cid:"));
        $this->assertStringContainsString('alt="chart"', $html);
        $this->assertStringContainsString('src="https://other.example/pixel.png"', $html, 'Other servers: a link.');
        $this->assertStringContainsString(e($pdf->url()), $html, 'Not an image: a link.');
        $this->assertStringContainsString('token=wrong', $html, 'A wrong token: a link.');
        $this->assertCount(3, $email->getAttachments());
        \Storage::disk('local')->delete('uploads/logo.png');
    }

    public function testInRepliesToCustomers()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Chart']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $image = Attachment::create('chart.png', 'image/png', null, base64_decode(self::PNG), null, true);

        $this->postAjax($agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id,
            'body' => '<p>Here: <img src="'.$image->url().'"></p>',
        ]);

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertStringContainsString('src="cid:', $email->getBody());
        $this->assertStringNotContainsString('/storage/attachment/', $email->getBody());
        $this->assertCount(1, $email->message->getAttachments());
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_MESSAGE)->count());
    }
}

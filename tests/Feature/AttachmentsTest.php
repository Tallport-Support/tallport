<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * Attachments in conversations: the sidebar list, deleting one, all of a
 * message as a zip, attached emails, searching by name, the reminder.
 */
class AttachmentsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    /**
     * A conversation whose customer message has the files.
     */
    protected function conversationWithFiles(array $files)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Casey <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Files']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        foreach ($files as $name => [$mime, $content]) {
            Attachment::create($name, $mime, null, $content, null, false, $thread->id);
        }
        $thread->has_attachments = true;
        $thread->save();
        $conversation->has_attachments = true;
        $conversation->save();

        return [$conversation, $thread];
    }

    public function testSidebarAndDownloadAll()
    {
        [$conversation, $thread] = $this->conversationWithFiles([
            'invoice.pdf' => ['application/pdf', '%PDF-1.4'],
            'photo.png'   => ['image/png', 'PNG'],
        ]);

        $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
            ->assertSee('attachments-block', false)->assertSee('invoice.pdf')
            ->assertSee(route('attachments.download_all', ['thread_id' => $thread->id]), false)
            ->assertSee('id="attachment-reminder"', false);

        $response = $this->get(route('attachments.download_all', ['thread_id' => $thread->id]))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $zip->open($file);
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('%PDF-1.4', $zip->getFromName('invoice.pdf'));
        $zip->close();

        $this->actingAs($this->createUser())->get(route('attachments.download_all', ['thread_id' => $thread->id]))->assertForbidden();
    }

    public function testDelete()
    {
        [$conversation, $thread] = $this->conversationWithFiles(['invoice.pdf' => ['application/pdf', '%PDF']]);
        $attachment = $thread->attachments()->first();

        $this->postAjax($this->agent, route('attachments.delete', ['id' => $attachment->id]), [])->assertJsonPath('status', 'error');
        $this->assertNotNull(Attachment::find($attachment->id), 'Not without the permission to delete conversations.');

        $this->agent->permissions = [User::PERM_DELETE_CONVERSATIONS => true];
        $this->agent->save();
        $this->postAjax($this->agent->fresh(), route('attachments.delete', ['id' => $attachment->id]), [])->assertJsonPath('status', 'success');

        $this->assertNull(Attachment::find($attachment->id));
        $this->assertFalse((bool) $thread->fresh()->has_attachments);
        $this->assertFalse((bool) $conversation->fresh()->has_attachments);
        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_ATTACHMENT_DELETED)->first();
        $this->assertSame('invoice.pdf', $line->action_data);
        $this->assertStringContainsString('deleted the attachment invoice.pdf', html_entity_decode(strip_tags($line->getActionText('', true))));
    }

    public function testAttachedEmail()
    {
        $eml = "From: Robin <robin@shop.example>\r\nTo: casey@customer.example.org\r\nSubject: Your <b>order</b>\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n"
            ."--b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Order shipped <script>alert(1)</script></p>\r\n"
            ."--b\r\nContent-Type: text/plain; name=\"label.txt\"\r\nContent-Disposition: attachment; filename=\"label.txt\"\r\n\r\nLABEL\r\n--b--\r\n";
        [$conversation, $thread] = $this->conversationWithFiles(['forwarded.eml' => ['message/rfc822', $eml]]);
        $attachment = $thread->attachments()->first();

        $page = $this->actingAs($this->agent)->get(route('attachments.email', ['id' => $attachment->id]))->assertOk();
        $page->assertSee('Robin &lt;robin@shop.example&gt;', false)->assertSee('Your &lt;b&gt;order&lt;/b&gt;', false)
            ->assertSee('Order shipped')->assertDontSee('<script>', false)->assertSee('label.txt');
        $this->assertStringContainsString("default-src 'none'", $page->headers->get('Content-Security-Policy'));
        $this->assertSame('LABEL', trim($this->get(route('attachments.email', ['id' => $attachment->id, 'part' => 0]))->getContent()));

        $this->actingAs($this->createUser())->get(route('attachments.email', ['id' => $attachment->id]))->assertForbidden();
    }

    public function testSearchByName()
    {
        [$conversation] = $this->conversationWithFiles(['Invoice-2026.pdf' => ['application/pdf', '%PDF']]);
        [$other] = $this->conversationWithFiles(['photo.png' => ['image/png', 'PNG']]);
        \App\Search\Indexer::index([$conversation->id, $other->id]);

        $ids = function ($q, $filters = []) {
            return collect(\App\Search\ConversationSearch::query($q, $filters, $this->agent)->get())->pluck('id')->all();
        };
        $this->actingAs($this->agent);
        $this->assertSame([$conversation->id], $ids('', ['attachment name' => 'invoice']));
        $this->assertSame([$conversation->id], $ids('attachment:invoice-2026'));
        $legacy = Conversation::search('', ['attachment name' => 'invoice'], $this->agent)->pluck('conversations.id')->all();
        $this->assertSame([$conversation->id], $legacy);
    }

    public function testReminderWordsFromTheEnvironmentFile()
    {
        require_once base_path('database/migrations/2026_10_12_010101_images_and_attachments.php');
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'EXTENDEDATTACHMENTS_REMINDER_PHRASES='.base64_encode("bijlage\nattached")."\n");

        \ImagesAndAttachments::importReminderPhrases($file);
        @unlink($file);

        $this->assertSame(['bijlage', 'attached'], \App\Http\Controllers\AttachmentsController::reminderPhrases());
    }
}

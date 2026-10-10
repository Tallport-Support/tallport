<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Mailbox folders, the conversation page, starting a conversation from the
 * UI, attachments and search.
 */
class ConversationPagesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveCustomerEmail(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * GET a page, following FreeScout's redirects (e.g. conversation pages
     * redirect to add ?folder_id=).
     */
    protected function getPage($user, $uri)
    {
        $response = $this->actingAs($user)->get($uri);
        for ($i = 0; $i < 3 && $response->isRedirect(); $i++) {
            $response = $this->actingAs($user)->get($response->headers->get('Location'));
        }

        return $response;
    }

    protected function uploadedFile($name, $contents)
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    public function testMailboxFolderListsConversations()
    {
        $this->receiveCustomerEmail(['subject' => 'Question about my order']);

        $response = $this->getPage($this->agent, '/mailbox/'.$this->mailbox->id);

        $response->assertStatus(200);
        $response->assertSee('Question about my order');
        $response->assertSee('Casey Customer');
    }

    public function testConversationPageListsItsFolderBesideIt()
    {
        $first = $this->receiveCustomerEmail(['subject' => 'First question']);
        $second = $this->receiveCustomerEmail(['subject' => 'Second question', 'from' => 'Robin Customer <robin@customer.example.org>']);

        $response = $this->getPage($this->agent, '/conversation/'.$first->id);

        $response->assertStatus(200);
        $response->assertSee('split-view__list', false);
        $response->assertSee('Second question');
        preg_match_all('/<a [^>]*conv-row__link[^>]*>/', $response->getContent(), $rows);
        $current = array_values(array_filter($rows[0], fn ($row) => str_contains($row, 'aria-current="page"')));
        $this->assertCount(1, $current);
        $this->assertStringContainsString('href="'.url('/conversation/'.$first->id).'"', $current[0], 'No folder in the URL.');
    }

    public function testUserWithoutAccessCannotSeeMailboxOrConversation()
    {
        $conversation = $this->receiveCustomerEmail(['subject' => 'Private question']);
        $outsider = $this->createUser();

        $this->getPage($outsider, '/mailbox/'.$this->mailbox->id)->assertStatus(403);
        $this->getPage($outsider, '/conversation/'.$conversation->id)->assertStatus(403);
    }

    public function testAdminCanSeeEveryMailbox()
    {
        $conversation = $this->receiveCustomerEmail(['subject' => 'Question about my order']);
        $admin = $this->createAdmin();

        $this->getPage($admin, '/mailbox/'.$this->mailbox->id)->assertStatus(200)->assertSee('Question about my order');
        $this->getPage($admin, '/conversation/'.$conversation->id)->assertStatus(200)->assertSee('Question about my order')
            ->assertDontSee('@endif', false);
    }

    public function testGuestIsSentToLogin()
    {
        $response = $this->get('/mailbox/'.$this->mailbox->id);

        $response->assertRedirect('/login');
    }

    public function testExpiredDialogSessionReturnsToTheCurrentPageAfterLogin()
    {
        $fragment = '/conversation/ajax-html/merge_conv?conversation_id=1';
        $page = '/mailbox/'.$this->mailbox->id;

        $this->withHeaders(['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get($fragment)->assertRedirect(route('login'));
        $this->assertSame(url($fragment), session('url.intended'));

        $this->get($page)->assertRedirect(route('login'));
        $this->assertSame(url($page), session('url.intended'));

        $this->actingAs($this->agent)->get($page)
            ->assertSee('<meta name="login-url" content="'.route('login').'">', false);
    }

    public function testNewConversationPageRenders()
    {
        $this->getPage($this->agent, '/mailbox/'.$this->mailbox->id.'/new-ticket')
            ->assertStatus(200)
            ->assertSee('New Conversation');
    }

    public function testAgentStartsConversationByEmail()
    {
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => '',
            'to'              => ['new.customer@customer.example.org'],
            'subject'         => 'Your invoice',
            'body'            => '<p>Please find your invoice below.</p>',
            'is_create'       => 1,
        ]);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Your invoice', $conversation->subject);
        // Sent without a status: active (not 0).
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->status);
        $this->assertSame('new.customer@customer.example.org', $conversation->customer_email);
        $this->assertEquals($this->agent->id, $conversation->created_by_user_id);
        $this->assertEquals(Conversation::SOURCE_TYPE_WEB, $conversation->source_type);

        $emails = $this->sentEmailsTo('new.customer@customer.example.org');
        $this->assertCount(1, $emails);
        $this->assertSame('Your invoice', $emails[0]->getSubject());
        $this->assertStringContainsString('Please find your invoice below.', $emails[0]->getBody());
        $this->assertNull($emails[0]->getHeaders()->get('In-Reply-To'), 'A new conversation is not a reply.');
    }

    public function testReplyWithAttachment()
    {
        Storage::fake('local_app');
        $conversation = $this->receiveCustomerEmail();

        $upload = $this->actingAs($this->agent)->post('/conversation/upload', [
            '_token' => csrf_token(),
            'attach' => 1,
            'file'   => $this->uploadedFile('invoice.txt', 'Invoice #42'),
        ], ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame('success', $upload->json()['status'], json_encode($upload->json()));

        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => '<p>Attached.</p>',
            // The editor sends every file uploaded while composing, and the
            // ones still attached; the difference gets deleted.
            'attachments_all' => [$upload->json()['attachment_id']],
            'attachments'     => [$upload->json()['attachment_id']],
        ]);
        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertTrue((bool)$reply->has_attachments);
        $this->assertSame(['invoice.txt'], $reply->attachments->pluck('file_name')->all());

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $attached = array_values(array_filter($email->getChildren(), function ($part) {
            return $part instanceof \Tests\Support\CapturedAttachment;
        }));
        $this->assertCount(1, $attached);
        $this->assertSame('invoice.txt', $attached[0]->getFilename());
        $this->assertSame('Invoice #42', $attached[0]->getBody());
    }

    public function testIncomingAttachmentIsStored()
    {
        Storage::fake('local_app');
        $boundary = 'b1';
        $conversation = $this->receiveCustomerEmail([
            'headers' => ['Content-Type' => 'multipart/mixed; boundary="'.$boundary.'"'],
            'body'    => "--$boundary\nContent-Type: text/plain; charset=UTF-8\n\nSee the photo.\n"
                ."--$boundary\nContent-Type: text/plain; name=\"notes.txt\"\nContent-Disposition: attachment; filename=\"notes.txt\"\nContent-Transfer-Encoding: base64\n\n"
                .base64_encode('Some notes')."\n--$boundary--",
        ]);

        $thread = $conversation->threads()->first();
        $this->assertStringContainsString('See the photo.', $thread->body);
        $this->assertTrue((bool)$thread->has_attachments);
        $attachment = $thread->attachments->first();
        $this->assertSame('notes.txt', $attachment->file_name);
        $this->assertSame('Some notes', $attachment->getFileContents());
    }

    public function testSearchFindsConversationBySubjectAndBody()
    {
        $this->receiveCustomerEmail(['subject' => 'Broken zipper', 'body' => 'The zipper on my jacket broke.']);
        $this->receiveCustomerEmail(['subject' => 'Other topic', 'body' => 'Unrelated.', 'from' => 'other@customer.example.org']);

        $by_subject = $this->getPage($this->agent, '/search?q=zipper');
        $by_subject->assertStatus(200);
        $by_subject->assertSee('Broken zipper');
        $by_subject->assertDontSee('Other topic');

        $this->getPage($this->agent, '/search?q=jacket')->assertSee('Broken zipper');
    }

    public function testSearchOnlyShowsAccessibleMailboxes()
    {
        $this->receiveCustomerEmail(['subject' => 'Broken zipper']);
        $outsider = $this->createUser();

        $this->getPage($outsider, '/search?q=zipper')->assertStatus(200)->assertDontSee('Broken zipper');
    }
}

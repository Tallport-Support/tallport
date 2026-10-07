<?php

namespace Tests\Feature;

use App\Ai\DraftJob;
use App\Conversation;
use App\KbArticle;
use App\Telegram\Telegram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * The less travelled paths of the smaller controllers: knowledge base,
 * AI drafts, app logs, All Mailboxes, external images, Telegram, and
 * the security page.
 */
class HttpControllerEdgeCasesTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function conversation($mailbox, $subject)
    {
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => $subject]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    // Knowledge base.

    public function testKnowledgeBaseNeedsThePermission()
    {
        $article = new KbArticle();
        $article->title = 'Refunds';
        $article->body = '<p>Five days.</p>';
        $article->created_by_user_id = $this->admin->id;
        $article->updated_by_user_id = $this->admin->id;
        $article->save();

        $this->postForm($this->agent, route('kb.save'), ['title' => 'Mine', 'mailbox_id' => $this->mailbox->id])->assertForbidden();
        $this->postForm($this->agent, route('kb.delete', ['id' => $article->id]), [])->assertForbidden();
        $this->assertNotNull(KbArticle::find($article->id));
        $this->assertSame(0, KbArticle::where('title', 'Mine')->count());

        $this->postAjax($this->agent, route('kb.ajax'), ['action' => 'nonsense'])->assertJson(['status' => 'error', 'msg' => 'Unknown action']);
    }

    // AI drafts.

    public function testDraftStillBeingMade()
    {
        $conversation = $this->conversation($this->mailbox, 'Question');
        $draft_job = new DraftJob();
        $draft_job->conversation_id = $conversation->id;
        $draft_job->user_id = $this->agent->id;
        $draft_job->status = DraftJob::STATUS_RUNNING;
        $draft_job->save();

        $this->actingAs($this->agent)->getJson(route('ai.drafts.show', ['id' => $draft_job->id]))
            ->assertExactJson(['status' => 'success', 'draft_status' => DraftJob::STATUS_RUNNING]);
        $this->actingAs($this->createUser())->getJson(route('ai.drafts.show', ['id' => $draft_job->id]))->assertForbidden();
    }

    public function testCustomerContextServerUnreachable()
    {
        Http::fake(['https://crm.example.org/*' => Http::failedConnection('Could not resolve host: crm.example.org')]);

        $response = $this->postAjax($this->admin, route('ai.customer_context.test'), [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);

        $response->assertJsonPath('status', 'error');
        $this->assertStringContainsString('Could not resolve host: crm.example.org', $response->json('msg'));
    }

    // App logs.

    public function testEmptyingALog()
    {
        $dir = sys_get_temp_dir().'/tallport-logs-'.uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/laravel.log', "[2026-10-01 10:00:00] production.ERROR: Something broke\n");
        config(['logviewer.storage_path' => $dir]);

        try {
            $this->actingAs($this->admin)->from('/app-logs/app?l=x')->get('/app-logs/app?clean='.urlencode(Crypt::encrypt('laravel.log')))
                ->assertRedirect('/app-logs/app?l=x');
            $this->assertFileExists($dir.'/laravel.log');
            $this->assertSame('', file_get_contents($dir.'/laravel.log'));
        } finally {
            (new Filesystem())->deleteDirectory($dir);
        }
    }

    // All Mailboxes.

    public function testAllMailboxesInAWideWindowOpensTheFirstConversation()
    {
        $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->conversation($this->mailbox, 'Where is my order');

        $this->withUnencryptedCookie('tallport_narrow', '0');
        $this->actingAs($this->agent)->get(route('mailboxes.all'))->assertOk()
            ->assertSee('data-conversation_id="'.$conversation->id.'"', false);
    }

    // External images.

    public function testBlockingImagesOfAnUnknownCustomer()
    {
        $this->postAjax($this->agent, route('conversations.external_images'), ['action' => 'block_customer', 'customer_id' => 999999])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
    }

    // Telegram.

    public function testTelegramUpdateWithoutAnIdIsAcknowledged()
    {
        Telegram::saveSettings($this->mailbox, ['enabled' => true, 'token' => '123456:SECRET-token', 'auto_reply' => '', 'ignore_start' => false]);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => Telegram::webhookSecret($this->mailbox)])
            ->postJson('/telegram/webhook/'.$this->mailbox->id, ['message' => ['text' => 'Hi']])
            ->assertExactJson(['ok' => true]);

        $this->assertSame(0, \DB::table('telegram_updates')->count());
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testTelegramNeedsATokenToBeEnabled()
    {
        $this->postForm($this->admin, route('mailboxes.telegram.save', ['id' => $this->mailbox->id]), ['enabled' => 1, 'token' => ''])
            ->assertSessionHasErrors(['token' => 'Enter the bot token from @BotFather.']);

        $this->assertFalse(Telegram::isEnabled($this->mailbox->fresh()));
    }

    // Security page.

    public function testForgettingAnotherUsersDevices()
    {
        $this->postForm($this->agent, route('users.security.forget_devices', ['id' => $this->admin->id]), [])->assertForbidden();
    }
}

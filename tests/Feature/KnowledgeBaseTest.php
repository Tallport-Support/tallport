<?php

namespace Tests\Feature;

use App\Ai\Document;
use App\Conversation;
use App\KbArticle;
use App\User;
use Tests\FeatureTestCase;

/**
 * The knowledge base: articles for agents, inserted in replies, used by the
 * AI Assistant.
 */
class KnowledgeBaseTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $support;
    protected $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->support = $this->createMailbox([$this->agent], ['name' => 'Support']);
        $this->sales = $this->createMailbox([], ['name' => 'Sales']);
    }

    protected function save(User $user, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post(route('kb.save'), array_merge(['_token' => csrf_token()], $data));
    }

    public function testArticles()
    {
        $this->actingAs($this->admin)->get(route('kb'))->assertOk()->assertSee('New Article');
        $this->get(route('kb.create'))->assertOk()->assertSee('kb_body', false);

        $this->save($this->admin, ['title' => 'Refunds', 'category' => 'Billing', 'mailbox_id' => '', 'body' => '<p>Refunds take 5 days.</p><script>x</script>'])
            ->assertRedirect();
        $refunds = KbArticle::where('title', 'Refunds')->first();
        $this->assertNull($refunds->mailbox_id);
        $this->assertStringNotContainsString('<script>', $refunds->body);
        $this->save($this->admin, ['title' => 'Sales prices', 'mailbox_id' => $this->sales->id, 'body' => '<p>Ask Sam.</p>']);
        $prices = KbArticle::where('title', 'Sales prices')->first();

        $this->get(route('kb.article', ['id' => $refunds->id]))->assertOk()->assertSee('Refunds take 5 days.');
        $this->get(route('kb', ['q' => '5 days']))->assertSee('Refunds')->assertDontSee('Sales prices');
        $this->get(route('kb', ['category' => 'Billing']))->assertSee('Refunds')->assertDontSee('Sales prices');
        $this->get(route('kb.edit', ['id' => $refunds->id]))->assertOk()->assertSee('value="Refunds"', false);

        // Agents read the articles of their mailboxes; writing needs the permission.
        $this->actingAs($this->agent)->get(route('kb'))->assertOk()->assertSee('Refunds')->assertDontSee('Sales prices')->assertDontSee('New Article');
        $this->get(route('kb.article', ['id' => $prices->id]))->assertNotFound();
        $this->get(route('kb.create'))->assertForbidden();
        $this->agent->permissions = [User::PERM_EDIT_KB => true];
        $this->agent->save();
        $agent = $this->agent->fresh();
        $this->save($agent, ['title' => 'Everywhere', 'mailbox_id' => ''])->assertSessionHasErrors('mailbox_id');
        $this->save($agent, ['title' => 'Support only', 'mailbox_id' => $this->support->id, 'body' => '<p>Hi</p>'])->assertRedirect();

        \Session::start();
        $this->actingAs($this->admin)->post(route('kb.delete', ['id' => $prices->id]), ['_token' => csrf_token()])->assertRedirect(route('kb'));
        $this->assertNull(KbArticle::find($prices->id));
    }

    public function testInsertInReplies()
    {
        $this->save($this->admin, ['title' => 'Refunds', 'category' => 'Billing', 'mailbox_id' => '', 'body' => '<p>Refunds take 5 days.</p>']);
        $this->save($this->admin, ['title' => 'Sales prices', 'mailbox_id' => $this->sales->id, 'body' => '<p>Ask Sam.</p>']);
        $this->receiveEmail($this->support, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->support->email]));
        $conversation = Conversation::where('mailbox_id', $this->support->id)->first();

        $page = $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk();
        $page->assertSee('id="kb-data"', false)->assertSee('Refunds')->assertDontSee('Sales prices');

        $refunds = KbArticle::where('title', 'Refunds')->first();
        $this->postAjax($this->agent, route('kb.ajax'), ['action' => 'get', 'article_id' => $refunds->id])
            ->assertJsonPath('status', 'success')->assertJsonPath('body', '<p>Refunds take 5 days.</p>');
        $prices = KbArticle::where('title', 'Sales prices')->first();
        $this->postAjax($this->agent, route('kb.ajax'), ['action' => 'get', 'article_id' => $prices->id])->assertJsonPath('status', 'error');
    }

    public function testAiAssistantDocuments()
    {
        $article = new KbArticle();
        $article->title = 'Refunds';
        $article->body = '<p>Refunds take <b>5 days</b>.</p>';
        $article->save();

        $documents = Document::where('source_identifier', 'kb:'.$article->id)->get();
        $this->assertEqualsCanonicalizing([$this->support->id, $this->sales->id], $documents->pluck('mailbox_id')->all(), 'Every mailbox.');
        $this->assertStringContainsString('# Refunds', $documents[0]->content);
        $this->assertStringContainsString('5 days', $documents[0]->content);
        $this->assertTrue($documents[0]->isPrivate());
        $new = $this->createMailbox([], ['name' => 'New']);
        $this->assertTrue(Document::where('source_identifier', 'kb:'.$article->id)->where('mailbox_id', $new->id)->exists(), 'A new mailbox too.');

        $article->mailbox_id = $this->support->id;
        $article->save();
        $this->assertSame([$this->support->id], Document::where('source_identifier', 'kb:'.$article->id)->pluck('mailbox_id')->all());

        $article->delete();
        $this->assertSame(0, Document::where('source_identifier', 'kb:'.$article->id)->count());
    }
}

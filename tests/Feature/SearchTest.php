<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Search\ConversationSearch;
use App\Search\Indexer;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Conversation search on the full-text index. MariaDB's full-text index
 * only sees committed rows, so these tests don't run in a transaction:
 * they empty the tables afterwards.
 */
class SearchTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support', 'email' => 'support@search.example']);
    }

    /**
     * No transaction: committed rows, removed when the test is done (with
     * DELETE: TRUNCATE recreates the full-text index, with stopwords).
     */
    public function beginDatabaseTransaction()
    {
        $this->beforeApplicationDestroyed(function () {
            \DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (\DB::select('SHOW TABLES') as $table) {
                $name = array_values((array) $table)[0];
                if ($name != 'migrations') {
                    \DB::table($name)->delete();
                }
            }
            \DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            \Option::$cache = [];
        });
    }

    protected function conversation($subject, $body, $from = 'Casey Customer <casey@customer.example.org>', $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => $from, 'to' => $mailbox->email, 'subject' => $subject, 'body' => $body]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function reply(Conversation $conversation, $body)
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $conversation->mailbox_id, 'conversation_id' => $conversation->id, 'body' => '<p>'.$body.'</p>',
        ]);
    }

    /**
     * IDs of the conversations found, in order.
     */
    protected function search($q, $filters = [], $user = null)
    {
        $user = $user ?: $this->agent;
        request()->merge(['sorting' => ['sort_by' => 'relevance', 'order' => 'desc']]);

        return collect(ConversationSearch::paginate($q, $filters, $user)->items())->pluck('id')->all();
    }

    protected function ready()
    {
        $this->artisan('tallport:search-index')->assertExitCode(0);
        $this->assertTrue(ConversationSearch::available());
    }

    public function testWordsAnywhereInTheConversation()
    {
        $jacket = $this->conversation('Broken zipper', 'The zipper of my winter jacket broke.');
        $this->reply($jacket, 'We will send a replacement right away.');
        $other = $this->conversation('Invoice', 'Please send the invoice again.');
        $this->ready();

        $this->assertSame([$jacket->id], $this->search('jacket replacement'), 'Words from different messages.');
        $this->assertSame([$jacket->id], $this->search('JACKET Replace'), 'Any case; the start of a word.');
        $this->assertSame([], $this->search('jacket invoice'), 'Every word must be there.');
        $this->assertSame([$jacket->id], $this->search('"winter jacket"'));
        $this->assertSame([], $this->search('"jacket winter"'), 'A phrase in that order.');
        $this->assertEqualsCanonicalizing([$jacket->id, $other->id], $this->search('send'));
        $this->assertSame([$other->id], $this->search('send -jacket'));
    }

    public function testStopwordsAndShortWordsAndChinese()
    {
        $about = $this->conversation('Question', 'What about my order? It is a QR code.');
        $chinese = $this->conversation('VPN', 'VPN服务费用是多少？');
        $this->ready();

        $this->assertSame([$about->id], $this->search('about'));
        $this->assertSame([$about->id], $this->search('qr code'), 'Words shorter than the index keeps.');
        $this->assertSame([], $this->search('od'), 'Short words from the start of a word.');
        $this->assertSame([$chinese->id], $this->search('服务'), 'Text without spaces between words.');
        $this->assertSame([$chinese->id], $this->search('vpn 费用'));
    }

    public function testBestMatchesFirst()
    {
        $body_only = $this->conversation('Hello', 'Something about a refund.');
        $subject = $this->conversation('Refund request', 'Please help.');
        $body_only->last_reply_at = now()->addHour();
        $body_only->save();
        $this->ready();

        $this->assertSame([$subject->id, $body_only->id], $this->search('refund'), 'The subject counts more than the date.');

        request()->merge(['sorting' => ['sort_by' => 'date', 'order' => 'desc']]);
        $this->assertSame([$body_only->id, $subject->id], collect(ConversationSearch::paginate('refund', [], $this->agent)->items())->pluck('id')->all(), 'Sorted by date when asked.');
    }

    public function testNumberFirst()
    {
        $first = $this->conversation('First', 'Nothing here.');
        $second = $this->conversation('Second', 'Order '.$first->number.' arrived late.');
        $this->ready();

        $this->assertSame($first->id, $this->search((string) $first->number)[0]);
    }

    public function testOperators()
    {
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales', 'email' => 'sales@search.example']);
        $casey = $this->conversation('Order help', 'Where is my order?');
        $robin = $this->conversation('Order status', 'My order is late.', 'Robin Buyer <robin@buyer.example.org>');
        $sales = $this->conversation('Order quote', 'A quote for an order please.', 'Robin Buyer <robin@buyer.example.org>', $other_mailbox);
        $robin->setStatus(Conversation::STATUS_CLOSED);
        $robin->has_attachments = true;
        $robin->save();
        $casey->created_at = now()->subDays(10);
        $casey->save();
        $this->ready();

        $this->assertEqualsCanonicalizing([$robin->id, $sales->id], $this->search('order from:robin'));
        $this->assertEqualsCanonicalizing([$robin->id, $sales->id], $this->search('from:robin@buyer.example.org'));
        $this->assertSame([$casey->id], $this->search('order -from:robin'));
        $this->assertSame([$sales->id], $this->search('to:sales@search.example'));
        $this->assertSame([$robin->id], $this->search('subject:status'));
        $this->assertSame([$sales->id], $this->search('order mailbox:sales'));
        $this->assertSame([], $this->search('order mailbox:nothing'));
        $this->assertSame([$robin->id], $this->search('order is:closed'));
        $this->assertEqualsCanonicalizing([$casey->id, $sales->id], $this->search('order is:open'));
        $this->assertSame([$robin->id], $this->search('has:attachment'));
        $this->assertSame([$casey->id], $this->search('order before:'.now()->subDays(5)->format('Y-m-d')));
        $this->assertEqualsCanonicalizing([$robin->id, $sales->id], $this->search('order after:'.now()->subDay()->format('Y-m-d')));
        $this->assertSame([$robin->id], $this->search('order', ['status' => [Conversation::STATUS_CLOSED]]), 'The filter form still works.');
    }

    public function testOnlyMailboxesTheUserCanSee()
    {
        $hidden = $this->createMailbox([], ['name' => 'Hidden', 'email' => 'hidden@search.example']);
        $this->conversation('Secret', 'A secret plan.', 'Casey Customer <casey@customer.example.org>', $hidden);
        $this->ready();

        $this->assertSame([], $this->search('secret'));
        $this->assertCount(1, $this->search('secret', [], $this->createAdmin()));
    }

    public function testSearchPageShowsExcerpts()
    {
        $conversation = $this->conversation('Broken zipper', 'Long ago I bought a coat. The zipper of my winter jacket broke yesterday & now it is cold.');
        $this->ready();

        $this->actingAs($this->agent)->get('/search?q=jacket+broke')
            ->assertOk()
            ->assertSee('<span class="conv-number">#'.$conversation->number.'</span>', false)
            ->assertSee('winter <mark>jacket</mark> <mark>broke</mark> yesterday &amp; now', false)
            ->assertSee('Search tips');
        $this->actingAs($this->agent)->get('/search?q=%23'.$conversation->number)->assertRedirect();
    }

    public function testNextPagesKeepTheOrderAndExcerpts()
    {
        $body_only = $this->conversation('Hello', 'Something about a refund.');
        $subject = $this->conversation('Refund request', 'Please help with a refund.');
        $body_only->last_reply_at = now()->addHour();
        $body_only->save();
        $this->ready();

        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action'  => 'conversations_pagination',
            'filter'  => ['q' => 'refund', 'f' => []],
            'params'  => [],
            'sorting' => ['sort_by' => 'relevance', 'order' => 'desc'],
            'page'    => 1,
        ]);

        $html = $response->json('html');
        $this->assertStringContainsString('<mark>refund</mark>', $html);
        $this->assertLessThan(strpos($html, 'Hello'), strpos($html, 'Refund request'));
        $this->assertStringContainsString('data-sorting_sort_by="relevance"', $html);
    }

    public function testIndexFollowsChanges()
    {
        $conversation = $this->conversation('Broken zipper', 'My jacket broke.');
        $this->ready();

        $conversation->subject = 'Torn sleeve';
        $conversation->save();
        $this->assertSame([$conversation->id], $this->search('sleeve'));

        $customer = $conversation->customer;
        $customer->first_name = 'Quinnella';
        $customer->save();
        $this->assertSame([], $this->search('quinnella'), 'Customers\' conversations are indexed in the background.');
        $this->artisan('tallport:search-index');
        $this->assertSame([$conversation->id], $this->search('quinnella'));

        $this->reply($conversation, 'A new sleeve is on its way.');
        $this->assertSame([$conversation->id], $this->search('"on its way"'));

        $conversation->deleteForever();
        $this->assertSame(0, \DB::table(Indexer::TABLE)->count());
    }

    public function testIndexingInTheBackground()
    {
        $conversation = $this->conversation('Broken zipper', 'My jacket broke.');
        $this->assertFalse(ConversationSearch::available(), 'Not until every conversation is indexed.');

        // Before: the search without the index.
        $this->actingAs($this->agent)->get('/search?q=jacket')->assertOk()->assertSee('My jacket broke.')->assertDontSee('Search tips');

        \DB::table(Indexer::TABLE)->delete();
        $this->artisan('tallport:search-index')->expectsOutputToContain('in the index: 1 of 1')->assertExitCode(0);
        $this->assertSame([$conversation->id], $this->search('jacket'));

        $this->artisan('tallport:search-index', ['--rebuild' => true])->assertExitCode(0);
        $this->assertNotNull(\DB::table(Indexer::TABLE)->value('indexed_at'));

        \DB::table('conversations')->delete();
        $this->artisan('tallport:search-index', ['--prune' => true, '--seconds' => 0])->expectsOutputToContain('Removed: 1');

        $admin = $this->createAdmin();
        $this->actingAs($admin)->get(route('system'))->assertSee('Search index');
    }

    public function testCustomerSearchMatchesEveryWord()
    {
        $this->conversation('Hello', 'Hi.', 'Casey Customer <casey@customer.example.org>');
        $this->conversation('Hello', 'Hi.', 'Casey Other <other@elsewhere.example.org>');

        $this->actingAs($this->agent)->get('/search?mode=customers&q=casey+customer')
            ->assertOk()->assertSee('casey@customer.example.org')->assertDontSee('other@elsewhere.example.org');
    }
}

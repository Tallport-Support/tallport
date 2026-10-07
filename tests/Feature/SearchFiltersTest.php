<?php

namespace Tests\Feature;

use App\Conversation;
use App\Search\ConversationSearch;
use App\Search\Indexer;
use App\Search\SearchQuery;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * The search form's filters and the conditions typed in the query (is:, mailbox:),
 * which don't need the full-text index (SearchTest covers the words, on MariaDB).
 */
class SearchFiltersTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function conversation($subject, $body = 'Where is my order?', $from = 'Casey Customer <casey@customer.example.org>')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $from, 'to' => $this->mailbox->email, 'subject' => $subject, 'body' => $body]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * IDs of the conversations found, by ID.
     */
    protected function search($q, $filters = [])
    {
        Indexer::index(Conversation::pluck('id')->all());
        $this->actingAs($this->agent);
        request()->merge(['sorting' => ['sort_by' => 'date', 'order' => 'desc']]);

        return ConversationSearch::query($q, $filters, $this->agent)->pluck('conversations.id')->sort()->values()->all();
    }

    public function testFormFilters()
    {
        $mine = $this->conversation('Broken zipper', 'My zipper broke.');
        $mine->user_id = $this->agent->id;
        $mine->has_attachments = true;
        $mine->save();
        $phone = $this->conversation('Called about a refund', 'Wants a refund.', 'Robin Buyer <robin@buyer.example.org>');
        $phone->type = Conversation::TYPE_PHONE;
        $phone->created_at = now()->subDays(10);
        $phone->save();
        $deleted = $this->conversation('Spam offer');
        $deleted->state = Conversation::STATE_DELETED;
        $deleted->save();
        // Robin also wrote in Casey's conversation.
        Thread::create($mine, Thread::TYPE_CUSTOMER, 'Me too', ['customer_id' => $phone->customer_id, 'created_by_customer_id' => $phone->customer_id, 'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_EMAIL]);
        $this->agent->followConversation($phone->id);

        $this->assertSame([$mine->id], $this->search('', ['assigned' => $this->agent->id]));
        $this->assertSame([$phone->id, $deleted->id], $this->search('', ['assigned' => Conversation::USER_UNASSIGNED]));
        $this->assertSame([$mine->id, $phone->id], $this->search('', ['customer' => $phone->customer_id]), 'Their own, and where they wrote.');
        $this->assertSame([$deleted->id], $this->search('', ['state' => [Conversation::STATE_DELETED]]));
        $this->assertSame([$mine->id], $this->search('', ['subject' => 'ZIPPER']));
        $this->assertSame([$mine->id], $this->search('', ['attachments' => 'yes']));
        $this->assertSame([$phone->id, $deleted->id], $this->search('', ['attachments' => 'no']));
        $this->assertSame([$phone->id], $this->search('', ['type' => Conversation::TYPE_PHONE]));
        $this->assertSame([$phone->id], $this->search('', ['body' => 'a REFUND']));
        $this->assertSame([$deleted->id], $this->search('', ['number' => $deleted->number]));
        $this->assertSame([$phone->id], $this->search('', ['following' => 'yes']));
        $this->assertSame([$mine->id], $this->search('', ['id' => $mine->id]));
        $this->assertSame([$phone->id], $this->search('', ['before' => now()->subDays(5)->format('Y-m-d')]));
        $this->assertSame([$mine->id, $deleted->id], $this->search('', ['after' => now()->subDay()->format('Y-m-d')]));
        $this->assertSame([$mine->id, $phone->id, $deleted->id], $this->search('', ['mailbox' => $this->mailbox->id]));
        $this->assertSame([$mine->id, $phone->id, $deleted->id], $this->search('', ['mailbox' => $this->createMailbox()->id]), 'Not someone else\'s mailbox: the user\'s.');
    }

    public function testAssigneeConditions()
    {
        $mine = $this->conversation('Mine');
        $mine->user_id = $this->agent->id;
        $mine->save();
        $theirs = $this->conversation('Theirs');
        $theirs->user_id = $this->createUser()->id;
        $theirs->save();
        $nobodys = $this->conversation('Nobody\'s');
        $this->agent->followConversation($theirs->id);

        $this->assertSame([$nobodys->id], $this->search('is:unassigned'));
        $this->assertSame([$mine->id], $this->search('is:mine'));
        $this->assertSame([$mine->id, $theirs->id], $this->search('is:assigned'));
        $this->assertSame([$theirs->id], $this->search('is:following'));
        $this->assertSame([$mine->id, $theirs->id, $nobodys->id], $this->search('is:anything'), 'Unknown: no condition.');
    }

    /**
     * A module's matches (search.conversations.or_where) are added to the words'.
     */
    public function testModulesAddMatches()
    {
        $chinese = $this->conversation('VPN', 'VPN服务费用是多少？');
        $tagged = $this->conversation('Tagged');
        $this->conversation('Other');
        \Eventy::addFilter('search.conversations.or_where', function ($query, $filters, $q) use ($tagged) {
            return $query->orWhere('conversations.id', $tagged->id);
        }, 20, 3);

        $this->assertSame([$chinese->id, $tagged->id], $this->search('服务'));
    }

    public function testQueriesThatAreNotWords()
    {
        $search = SearchQuery::parse('"" -');
        $this->assertSame([], $search->terms);
        $this->assertFalse(SearchQuery::isIndexable('!!!'));
    }

    public function testIndexingNothing()
    {
        $this->assertSame(0, Indexer::index([null, 0]));
        (new \ReflectionProperty(Indexer::class, 'pending'))->setValue(null, []);
        Indexer::flush();
        Indexer::touchCustomer(null);
        $this->assertSame(0, \DB::table(Indexer::TABLE)->count());
    }

    /**
     * A customer's Nostr keys (label and npub) find their conversations.
     */
    public function testNostrKeysAreIndexed()
    {
        $conversation = $this->conversation('Nostr question');
        $pubkey = str_repeat('ab', 32);
        \App\Nostr\CustomerKey::link($conversation->customer, $pubkey, \App\Nostr\CustomerKey::SOURCE_AUTO, 'Casey phone');
        $key = \App\Nostr\CustomerKey::byPubkey($pubkey);

        $people = Indexer::document($conversation->fresh())['people'];

        $this->assertStringContainsString('Casey phone', $people);
        $this->assertStringContainsString($key->getNpub(), $people);
    }
}

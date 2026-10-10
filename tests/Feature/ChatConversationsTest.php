<?php

namespace Tests\Feature;

use App\Conversation;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Misc\ChatConversations;
use App\Telegram\Telegram;
use App\Thread;
use Illuminate\Support\Facades\Event;
use Tests\FeatureTestCase;

class ChatConversationsTest extends FeatureTestCase
{
    protected function conversation($mailbox, $customer, $channel)
    {
        return Conversation::create([
            'type' => Conversation::TYPE_EMAIL,
            'subject' => 'Help',
            'mailbox_id' => $mailbox->id,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'channel' => $channel,
        ], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'First message']], $customer)['conversation']->fresh();
    }

    public function testLookupIsScopedToMailboxCustomerAndChannel()
    {
        $mailbox = $this->createMailbox();
        $customer = $this->createCustomer();
        $expected = $this->conversation($mailbox, $customer, Telegram::CHANNEL);
        $this->conversation($this->createMailbox(), $customer, Telegram::CHANNEL);
        $this->conversation($mailbox, $this->createCustomer(), Telegram::CHANNEL);
        $this->conversation($mailbox, $customer, config('nostr.channel'));

        $actual = ChatConversations::latest($mailbox, $customer, Telegram::CHANNEL);

        $this->assertSame($expected->id, $actual->id);
    }

    /**
     * @dataProvider channels
     */
    public function testLatestConversationUsesCreationDateAndAnIdTieBreaker($channel)
    {
        $channel = $channel === 'telegram' ? Telegram::CHANNEL : config('nostr.channel');
        $mailbox = $this->createMailbox();
        $customer = $this->createCustomer();
        $recent = $this->conversation($mailbox, $customer, $channel);
        $imported = $this->conversation($mailbox, $customer, $channel);
        $imported->created_at = now()->subYear();
        $imported->save();

        $this->assertSame($recent->id, ChatConversations::latest($mailbox, $customer, $channel)->id);

        $same_second = $this->conversation($mailbox, $customer, $channel);
        $same_second->created_at = $recent->created_at;
        $same_second->save();
        $this->assertSame($same_second->id, ChatConversations::latest($mailbox, $customer, $channel)->id);
    }

    /**
     * @dataProvider conversationPolicies
     */
    public function testAllChannelsApplyTheMailboxConversationPolicy($channel, $status, $state, $start_new, $days, $continue)
    {
        $channel = $channel === 'telegram' ? Telegram::CHANNEL : config('nostr.channel');
        $mailbox = $this->createMailbox();
        $mailbox->setMetaParam('chat_start_new', $start_new);
        $mailbox->setMetaParam('chat_reopen_days', 7, true);
        $customer = $this->createCustomer();
        $conversation = $this->conversation($mailbox, $customer, $channel);
        $conversation->status = $status;
        $conversation->state = $state;
        $conversation->last_reply_at = now()->subDays($days);
        $conversation->save();

        $selected = ChatConversations::latest($mailbox, $customer, $channel);
        $result = ChatConversations::receive($selected, $customer, [
            'mailbox_id' => $mailbox->id,
            'channel' => $channel,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'subject' => 'Next question',
        ], ['body' => 'Hello again']);

        if ($continue) {
            $this->assertSame($conversation->id, $result['conversation']->id);
        } else {
            $this->assertNotSame($conversation->id, $result['conversation']->id);
        }
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $result['conversation']->fresh()->status);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $result['conversation']->fresh()->state);
    }

    public static function channels()
    {
        return ['telegram' => ['telegram'], 'nostr' => ['nostr']];
    }

    public static function conversationPolicies()
    {
        $cases = [];
        foreach (self::channels() as $name => [$channel]) {
            $cases[$name.' closed, continue'] = [$channel, Conversation::STATUS_CLOSED, Conversation::STATE_PUBLISHED, false, 2, true];
            $cases[$name.' closed, start new'] = [$channel, Conversation::STATUS_CLOSED, Conversation::STATE_PUBLISHED, true, 2, false];
            $cases[$name.' deleted, restore'] = [$channel, Conversation::STATUS_CLOSED, Conversation::STATE_DELETED, false, 2, true];
            $cases[$name.' deleted, start new'] = [$channel, Conversation::STATUS_CLOSED, Conversation::STATE_DELETED, true, 2, false];
            $cases[$name.' inactive, start new'] = [$channel, Conversation::STATUS_ACTIVE, Conversation::STATE_PUBLISHED, false, 8, false];
            $cases[$name.' active, continue'] = [$channel, Conversation::STATUS_ACTIVE, Conversation::STATE_PUBLISHED, true, 2, true];
        }

        return $cases;
    }

    public function testIncomingMessageUsesTheSelectedConversationAndDispatchesOneReplyEvent()
    {
        $mailbox = $this->createMailbox();
        $customer = $this->createCustomer();
        $selected = $this->conversation($mailbox, $customer, Telegram::CHANNEL);
        $newer = $this->conversation($mailbox, $customer, Telegram::CHANNEL);
        $selected->setStatus(Conversation::STATUS_CLOSED);
        $selected->save();
        Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        $result = ChatConversations::receive($selected, $customer, [], ['body' => 'Still need help']);

        $this->assertSame($selected->id, $result['thread']->conversation_id);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $selected->fresh()->status);
        $this->assertSame(['First message', 'Still need help'], $selected->threads()->orderBy('id')->pluck('body')->all());
        $this->assertSame(1, $newer->threads()->count());
        Event::assertDispatchedTimes(CustomerReplied::class, 1);
        Event::assertNotDispatched(CustomerCreatedConversation::class);
    }

    public function testNewConversationKeepsSourceMetadataAndDispatchesOneCreationEvent()
    {
        $mailbox = $this->createMailbox();
        $customer = $this->createCustomer();
        Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        $result = ChatConversations::receive(null, $customer, [
            'subject' => 'Chat question',
            'mailbox_id' => $mailbox->id,
            'channel' => Telegram::CHANNEL,
            'source_type' => Conversation::SOURCE_TYPE_WEB,
        ], ['body' => 'Please help']);

        $conversation = $result['conversation']->fresh();
        $this->assertSame('Chat question', $conversation->subject);
        $this->assertSame(Conversation::SOURCE_TYPE_WEB, (int) $conversation->source_type);
        $this->assertSame($customer->id, $result['thread']->created_by_customer_id);
        $this->assertSame('Please help', $result['thread']->fresh()->body);
        Event::assertDispatchedTimes(CustomerCreatedConversation::class, 1);
        Event::assertNotDispatched(CustomerReplied::class);
    }
}

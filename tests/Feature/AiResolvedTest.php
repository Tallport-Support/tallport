<?php

namespace Tests\Feature;

use App\Ai\Agents\ChatIntake;
use App\Ai\Agents\ResolvedCheck;
use App\Ai\ChatAgent;
use App\Ai\Resolved;
use App\Ai\Usage;
use App\Conversation;
use App\Option;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Close When Resolved (App\Ai\Resolved, AI faked): a customer's reply saying they need nothing more
 * closes the conversation, as the AI's; anything else, or any doubt, leaves it to the team.
 */
class AiResolvedTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox = $this->createMailbox([$this->agent]);
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations', 'drafts']]);
        Option::$cache = [];

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'message_id' => 'first@customer.example.org',
            'body' => 'The app crashes when I open a conversation.',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->firstOrFail();
        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id, 'body' => 'Please update to version 2.3, which fixes it.']);
    }

    /**
     * The customer answers by email.
     */
    protected function customerReplies($body)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Re: Question about my order',
            'in_reply_to' => 'first@customer.example.org', 'references' => ['first@customer.example.org'], 'body' => $body,
        ]));

        return $this->conversation->fresh();
    }

    public function testItClosesWhenTheCustomerNeedsNothingMore()
    {
        $prompts = [];
        ResolvedCheck::fake(function ($prompt) use (&$prompts) {
            $prompts[] = (string) $prompt;

            return ['resolved' => true];
        });

        $sent = count($this->sentEmailsTo('casey@customer.example.org'));
        $conversation = $this->customerReplies('Thanks, it works now!');
        $this->assertTrue($conversation->isClosed());
        $ai = ChatAgent::user();
        $this->assertSame($ai->id, (int) $conversation->closed_by_user_id);
        $this->assertNull($conversation->user_id, 'The AI takes nothing.');
        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_STATUS_CHANGED)->firstOrFail();
        $this->assertSame('AI marked as Closed', strip_tags($line->getActionText('', false, true, null, $line->getActionPerson())));
        // It read the customer's message with the team's answer before it.
        $this->assertCount(1, $prompts);
        $this->assertMatchesRegularExpression('/"from": "staff",\s+"body": "Please update to version 2.3, which fixes it.".*"from": "customer",\s+"body": "Thanks, it works now!"/s', $prompts[0]);
        $this->assertSame(Usage::FEATURE_CLOSING, Usage::where('conversation_id', $conversation->id)->value('feature'));
        // Nothing went to the customer.
        $this->assertCount($sent, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testAnythingElseStaysWithTheTeam()
    {
        ResolvedCheck::fake([['resolved' => false]]);
        $this->assertTrue($this->customerReplies('Thanks, but it still crashes.')->isActive());
        ResolvedCheck::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'it still crashes'));

        // The AI failing: it stays as it is.
        ResolvedCheck::fake(fn () => throw new \RuntimeException('Down'));
        $this->assertTrue($this->customerReplies('Thanks, it works now!')->isActive());
    }

    /**
     * Not asked: switched off for the mailbox, a long message, an auto-responder's, or a message
     * that's no longer the latest; nor about a new conversation.
     */
    public function testWhenItIsntAsked()
    {
        ResolvedCheck::fake(fn () => ['resolved' => true])->preventStrayPrompts();
        ResolvedCheck::assertNeverPrompted();

        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations', 'drafts', 'closing']]);
        Option::$cache = [];
        $this->assertTrue($this->customerReplies('Thanks, it works now!')->isActive());
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations', 'drafts']]);
        Option::$cache = [];

        $this->assertTrue($this->customerReplies(str_repeat('It works now, but let me tell you more. ', 40))->isActive());

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Automatic reply: Re: Question about my order',
            'in_reply_to' => 'first@customer.example.org', 'body' => 'I am out of the office.', 'headers' => ['Auto-Submitted' => 'auto-replied'],
        ]));
        $this->assertTrue($this->conversation->fresh()->isActive());

        // The team replied since (the check runs a few seconds later).
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\AiCheckResolved::class]);
        $customer_thread = $this->customerReplies('Thanks, it works now!')->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id', 'desc')->first();
        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id, 'body' => 'Glad to hear it!', 'status' => Conversation::STATUS_ACTIVE]);
        Resolved::check($this->conversation->fresh(), $customer_thread->id);
        $this->assertTrue($this->conversation->fresh()->isActive());

        ResolvedCheck::assertNeverPrompted();
    }

    public function testNotInAConversationTheAiAgentIsAskingIn()
    {
        Option::set('aiassistant.mailbox_agent_channels', [$this->mailbox->id => ['email']]);
        Option::$cache = [];
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\AiChatAgentSilence::class]);
        ChatIntake::fake(fn () => ['action' => 'ask', 'message' => 'Which version do you use?', 'has_request' => true, 'subject' => '', 'language' => 'en', 'translation' => '']);
        ResolvedCheck::fake(fn () => ['resolved' => true])->preventStrayPrompts();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Dana <dana@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Login fails', 'message_id' => 'dana@customer.example.org',
            'body' => 'I cannot log in.',
        ]));
        $conversation = Conversation::where('subject', 'Login fails')->firstOrFail();
        $this->assertTrue(ChatAgent::handles($conversation));
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Dana <dana@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Re: Login fails',
            'in_reply_to' => 'dana@customer.example.org', 'body' => 'Version 2.3.',
        ]));

        ResolvedCheck::assertNeverPrompted();
    }

    public function testTheMailboxsAiPageTurnsItOff()
    {
        $admin = $this->createAdmin();
        $page = route('mailboxes.ai', ['id' => $this->mailbox->id]);
        $this->actingAs($admin)->get($page)->assertOk()->assertSeeInOrder(['Drafts', 'Close When Resolved', 'A conversation closes when the customer writes'], false);

        \Session::start();
        $this->actingAs($admin)->post(route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'features' => ['summaries' => 1]])->assertRedirect($page);
        Option::$cache = [];
        $this->assertFalse(\App\Ai\Settings::enabled('closing', $this->mailbox));
        $this->actingAs($admin)->post(route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'features' => ['closing' => 1]]);
        Option::$cache = [];
        $this->assertTrue(\App\Ai\Settings::enabled('closing', $this->mailbox));
    }
}

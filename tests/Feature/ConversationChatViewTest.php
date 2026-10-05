<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Livewire\ConversationThread;
use App\Telegram\Telegram;
use App\Thread;
use App\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The chat view of a conversation (the user's Conversation View for its channel):
 * the history oldest first under day dividers, the composer docked and open.
 */
class ConversationChatViewTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Broken zipper',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
    }

    public function testTheViewFollowsTheUsersChoiceForTheChannel()
    {
        // Email: the email view.
        $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()
            ->assertDontSee('conv-chat-view', false)->assertSee('conv-heading__top', false);

        // Telegram: the chat view, unless the user chose the email view.
        $this->conversation->channel = Telegram::CHANNEL;
        $this->conversation->save();
        $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()
            ->assertSee('conv-chat-view', false)->assertSee('f-history', false)->assertSee('conv-composer-docked', false);

        $this->agent->conversation_views = json_encode([Telegram::CHANNEL => User::VIEW_EMAIL]);
        $this->agent->save();
        $this->actingAs($this->agent->fresh())->get($this->conversation->url())->assertOk()->assertDontSee('conv-chat-view', false);
    }

    public function testTheHistoryRunsOldestFirstUnderDayDividers()
    {
        $first = $this->conversation->threads()->first();
        $first->created_at = now()->subDays(3);
        $first->save();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id,
            'body'   => '<p>Checked the logs.</p>', 'is_note' => 1,
        ]);
        $reply = $this->conversation->threads()->where('type', Thread::TYPE_NOTE)->orderBy('id', 'desc')->first();

        $html = Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation, 'chat' => true])->html();
        $this->assertStringContainsString('f-history', $html);
        $this->assertLessThan(strpos($html, 'thread-'.$reply->id.'"'), strpos($html, 'thread-'.$first->id.'"'));
        // A divider and a list for each day.
        $this->assertSame(2, substr_count($html, 'f-divider'));
        $this->assertSame(2, preg_match_all('#<ol role="list" class="f-thread f-thread--compact"#', $html));
        // The component's root is its own element, not a day's list.
        $this->assertMatchesRegularExpression('#^\s*<div [^>]*wire:id="[^"]+"[^>]*class="conv-thread-host"#', preg_replace('#<!--.*?-->#s', '', $html));
        $this->assertStringContainsString('Today', $html);

        // The email view: newest first, no dividers.
        $html = Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation])->html();
        $this->assertGreaterThan(strpos($html, 'thread-'.$reply->id.'"'), strpos($html, 'thread-'.$first->id.'"'));
        $this->assertStringNotContainsString('f-divider', $html);
    }

    public function testTheComposerStaysOpenAndSwitchesWhileEmpty()
    {
        $composer = Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation, 'chat' => true])
            ->assertSet('mode', 'reply');

        // Empty: the toolbar's Note switches it; a discarded note leaves a reply open.
        $composer->call('open', 'note')->assertSet('mode', 'note')
            ->call('discard')->assertSet('mode', 'reply');

        // Written: it stays as it is.
        $composer->set('body', '<p>Half a reply</p>')->call('open', 'note')->assertSet('mode', 'reply');

        // Sent: the conversation again, without a full page load.
        $composer->call('send')->assertRedirect();
        $this->assertTrue($composer->effects['redirectUsingNavigate'] ?? false);

        // The email view opens on request only.
        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation])->assertSet('mode', '');
    }

    public function testTheChatComposerOnlySends()
    {
        // A reply never closes the chat (the toolbar does), and stays in the conversation.
        $this->agent->reply_status = Conversation::STATUS_CLOSED;
        $this->agent->after_send = \App\MailboxUser::AFTER_SEND_NEXT;
        $this->agent->save();

        $composer = Livewire::actingAs($this->agent->fresh())->test(ConversationComposer::class, ['conversation' => $this->conversation, 'chat' => true])
            ->assertSet('status', Conversation::STATUS_PENDING)
            ->assertSeeHtml('btn-reply-submit')->assertDontSeeHtml('dropdown-send-status')->assertDontSeeHtml('name="status"');
        $composer->set('body', '<p>On it.</p>')->call('send')->assertRedirect($this->conversation->url());
        $this->assertSame(Conversation::STATUS_PENDING, $this->conversation->fresh()->status);

        // The email view keeps the user's choices.
        Livewire::actingAs($this->agent->fresh())->test(ConversationComposer::class, ['conversation' => $this->conversation])
            ->call('open', 'reply')->assertSet('status', Conversation::STATUS_CLOSED)->assertSeeHtml('dropdown-send-status');
    }
}

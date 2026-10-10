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
            ->assertDontSee('conv-chat ', false)->assertSee('conv-heading__top', false);

        // Telegram: the chat view, unless the user chose the email view.
        $this->conversation->channel = Telegram::CHANNEL;
        $this->conversation->save();
        $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()
            ->assertSee('conv-chat ', false)->assertSee('f-history', false)->assertSee('conv-composer-docked', false);

        $this->agent->conversation_views = json_encode([Telegram::CHANNEL => User::VIEW_EMAIL]);
        $this->agent->save();
        $this->actingAs($this->agent->fresh())->get($this->conversation->url())->assertOk()->assertDontSee('conv-chat ', false);
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
        // Just the name and time: no sender and recipient lines.
        $this->assertStringNotContainsString('thread-recipients', $html);

        // The email view: newest first, no dividers.
        $html = Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation])->html();
        $this->assertGreaterThan(strpos($html, 'thread-'.$reply->id.'"'), strpos($html, 'thread-'.$first->id.'"'));
        $this->assertStringNotContainsString('f-divider', $html);
        $this->assertStringContainsString('thread-recipients', $html);
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

        // Sent: no page load; the history shows it, the composer is ready for the next one.
        $composer->call('send')->assertReturned(true)->assertNoRedirect()
            ->assertDispatched('conversation-thread-created')->assertDispatched('fruit-editor-set')
            ->assertSet('body', '')->assertSet('mode', 'reply');

        // The email view opens on request only.
        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation])->assertSet('mode', '');
    }

    /**
     * A reply being written isn't a message in a chat: its draft isn't in the history, and it's
     * back in the composer (for the one who wrote it) when the chat opens again.
     */
    public function testDraftsStayInTheComposer()
    {
        $composer = Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation, 'chat' => true])
            ->set('body', '<p>Half a reply</p>')->call('saveDraft');
        $draft = $this->conversation->threads()->where('state', Thread::STATE_DRAFT)->first();
        $this->assertNotNull($draft);

        Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation, 'chat' => true])
            ->assertDontSee('Half a reply');
        // The email view still lists it.
        Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation])
            ->assertSee('Half a reply');

        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh(), 'chat' => true])
            ->assertSet('thread_id', $draft->id)->assertSet('body', '<p>Half a reply</p>');
        // Someone else's draft isn't theirs to continue.
        $colleague = $this->createUser();
        $this->mailbox->users()->attach($colleague->id);
        Livewire::actingAs($colleague)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh(), 'chat' => true])
            ->assertSet('body', '');
    }

    public function testTheChatComposerOnlySends()
    {
        // A message leaves the status as it is (the toolbar's), and stays in the conversation.
        $this->agent->reply_status = Conversation::STATUS_CLOSED;
        $this->agent->after_send = \App\MailboxUser::AFTER_SEND_NEXT;
        $this->agent->save();

        $composer = Livewire::actingAs($this->agent->fresh())->test(ConversationComposer::class, ['conversation' => $this->conversation, 'chat' => true])
            ->assertSet('status', Conversation::STATUS_ACTIVE)
            ->assertSeeHtml('f-editor--inline')->assertSeeHtml('data-fruit-enter="submit"')->assertSeeHtml('f-composer__send')->assertDontSeeHtml('btn-reply-submit')
            // The pickers open above the field, docked at the bottom, not below it.
            ->assertSeeHtml('f-floating-disclosure--above editor-picker saved-replies-picker')->assertDontSeeHtml('dropdown-send-status')->assertDontSeeHtml('name="status"');
        // The text comes with the send (the browser may have cleared the editor already).
        $composer->set('body', '')->call('send', null, '<p>On it.</p>')->assertReturned(true)->assertNoRedirect();
        $this->assertSame('<p>On it.</p>', $this->conversation->threads()->where('type', \App\Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->value('body'));
        $this->assertSame(Conversation::STATUS_ACTIVE, $this->conversation->fresh()->status);

        // The email view keeps the user's choices.
        Livewire::actingAs($this->agent->fresh())->test(ConversationComposer::class, ['conversation' => $this->conversation])
            ->call('open', 'reply')->assertSet('status', Conversation::STATUS_CLOSED)->assertSeeHtml('dropdown-send-status')->assertSeeHtml('data-fruit-enter="newline"')->assertDontSeeHtml('f-editor--inline')->assertDontSeeHtml('f-floating-disclosure--above');
    }

    public function testTheEditorAllowsTheChannelsFormatting()
    {
        $composer = fn () => Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh(), 'chat' => true]);

        // Email: everything.
        $composer()->assertDontSeeHtml('data-fruit-formats');

        // Telegram: what Telegram carries; a note (internal) has everything.
        $this->conversation->channel = Telegram::CHANNEL;
        $this->conversation->save();
        $composer()->assertSeeHtml('data-fruit-formats="bold italic link blockquote"')
            ->call('open', 'note')->assertDontSeeHtml('data-fruit-formats');

        // Nostr: plain text.
        $this->conversation->channel = \App\Nostr\Nostr::channel();
        $this->conversation->save();
        $composer()->assertSeeHtml('data-fruit-formats=""');
    }

    public function testAPersonsMessagesMinutesApartAreGrouped()
    {
        $first = $this->conversation->threads()->first();
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Re: Broken zipper',
            'in_reply_to' => $first->message_id,
        ]));
        $second = $this->conversation->threads()->orderBy('id', 'desc')->first();
        $this->assertSame($this->conversation->id, $second->conversation_id);
        $this->assertTrue(Thread::continues($second, $first));

        $html = Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation, 'chat' => true])->html();
        $this->assertSame(1, substr_count($html, 'f-message--continued'));
        // The time shows once per run: none on hover beside the continued message.
        $this->assertSame(1, substr_count($html, 'class="thread-date"'));

        // Not after a gap.
        $second->created_at = $first->created_at->copy()->addMinutes(10);
        $this->assertFalse(Thread::continues($second, $first));
    }
}

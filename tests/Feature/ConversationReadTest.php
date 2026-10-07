<?php

namespace Tests\Feature;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\Livewire\ConversationList;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Read and unread, per user (App\ConversationRead): unread while someone else has added to a
 * conversation since the user last opened it.
 */
class ConversationReadTest extends FeatureTestCase
{
    protected $agent;
    protected $teammate;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->teammate = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent, $this->teammate]);
    }

    protected function conversation($subject = 'Unread question')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function unread($conversation, $user)
    {
        return ConversationRead::unreadIds([$conversation], $user) == [$conversation->id];
    }

    public function testOpeningReadsItForThatUserOnly()
    {
        $conversation = $this->conversation();
        $this->assertTrue($this->unread($conversation, $this->agent));

        $this->actingAs($this->agent)->get($conversation->url())->assertOk();
        $this->assertFalse($this->unread($conversation, $this->agent));
        $this->assertTrue($this->unread($conversation, $this->teammate));

        // Shown after wire:navigate.
        $this->postAjax($this->teammate, '/conversation/ajax', ['action' => 'viewed', 'conversation_id' => $conversation->id])->assertJson(['status' => 'success']);
        $this->assertFalse($this->unread($conversation, $this->teammate));
    }

    public function testOwnRepliesAndEventsDontMakeItUnread()
    {
        $conversation = $this->conversation();
        ConversationRead::markRead($conversation->id, $this->agent);
        ConversationRead::markRead($conversation->id, $this->teammate);

        $conversation->createUserThread($this->agent, 'On its way.');
        $conversation->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $this->assertSame(Thread::TYPE_LINEITEM, (int) $conversation->threads()->orderBy('id', 'desc')->value('type'));
        $this->assertFalse($this->unread($conversation, $this->agent));
        // A teammate's reply is new to them.
        $this->assertTrue($this->unread($conversation, $this->teammate));

        $conversation->createUserThread($this->teammate, 'Checked the warehouse.', ['type' => Thread::TYPE_NOTE]);
        $this->assertTrue($this->unread($conversation, $this->agent));
    }

    public function testWhatCameBeforeIsRead()
    {
        $conversation = $this->conversation();
        \Option::set(ConversationRead::OPTION_READ_UP_TO, (int) Thread::max('id'));

        $this->assertFalse($this->unread($conversation, $this->agent));
    }

    /**
     * Mark as Read and Unread: the row menu, and the selection bar's More menu. Unread stays
     * until the conversation is opened again.
     */
    public function testMarkAsReadAndUnread()
    {
        $first = $this->conversation('First question');
        $second = $this->conversation('Second question');
        $folder = $this->mailbox->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();

        // The dot, and Unread for screen readers.
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSeeHtml('wire:click="rowRead('.$first->id.', 1)"')->assertSeeHtml('<span class="f-item-row__unread" aria-hidden="true"></span><span class="f-sr-only">Unread</span>');
        $this->assertSame(2, substr_count($list->html(), 'f-item-row__unread'));
        $list->call('rowRead', $first->id, 1)->assertSeeHtml('wire:click="rowRead('.$first->id.', 0)"');
        $this->assertSame(1, substr_count($list->html(), 'f-item-row__unread'));
        $this->assertFalse($this->unread($first, $this->agent));
        $this->assertTrue($this->unread($second, $this->agent));

        $list->set('selected', [(string) $first->id, (string) $second->id])
            ->assertSeeInOrder(['Mark as Read', 'Mark as Unread'])
            ->call('markRead', 1);
        $this->assertFalse($this->unread($second, $this->agent));

        $list->call('markRead', 0);
        $this->assertTrue($this->unread($first, $this->agent));
        $this->assertTrue($this->unread($second, $this->agent));
        $this->assertTrue($this->unread($first, $this->agent), 'Nothing new, still unread.');

        $this->actingAs($this->agent)->get($first->url())->assertOk();
        $this->assertFalse($this->unread($first, $this->agent));

        // Only conversations the user may see.
        $outsider = $this->createUser();
        Livewire::actingAs($outsider)->test(ConversationList::class, ['folder' => $folder])->call('markRead', 1, [$second->id]);
        $this->assertSame(0, ConversationRead::where('user_id', $outsider->id)->count());
    }

    public function testReadsGoWithTheirConversationOrUser()
    {
        $conversation = $this->conversation();
        ConversationRead::markRead($conversation->id, $this->agent);
        ConversationRead::markRead($conversation->id, $this->teammate);

        $this->teammate->delete();
        $this->assertSame(1, ConversationRead::where('conversation_id', $conversation->id)->count());

        Conversation::deleteConversationsForever([$conversation->id]);
        $this->assertSame(0, ConversationRead::where('conversation_id', $conversation->id)->count());
    }

    public function testNoSelectButton()
    {
        $this->conversation();
        $folder = $this->mailbox->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSee('Unread question')->assertDontSeeHtml('data-fruit-select-toggle')->assertSeeHtml('conv-checkbox');
    }
}

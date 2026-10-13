<?php

namespace Tests\Feature;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\Livewire\ConversationList;
use App\Misc\Sidebar;
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
        $viewed = $this->postAjax($this->teammate, '/conversation/ajax', ['action' => 'viewed', 'conversation_id' => $conversation->id])->assertJson(['status' => 'success']);
        $unassigned = $this->mailbox->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();
        $this->assertSame(0, $viewed->json('sidebar_counts.folders.'.$unassigned->id));
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
        $this->assertSame([], ConversationRead::unreadMessageCounts($this->agent)->all());
    }

    public function testSidebarCountsUnreadMessagesForEachUserAndMailbox()
    {
        $conversation = $this->conversation();
        $unassigned = $this->mailbox->folders()->where('type', Folder::TYPE_UNASSIGNED)->first();
        ConversationRead::markRead($conversation->id, $this->agent);

        $conversation->createUserThread($this->teammate, 'First update.');
        $conversation->createUserThread($this->teammate, 'Second update.');
        $this->assertSame(2, Sidebar::unreadCounts($this->agent)['folders'][$unassigned->id]);
        $this->assertSame(1, Sidebar::unreadCounts($this->teammate)['folders'][$unassigned->id], 'A user does not count their own replies.');

        $second_mailbox = $this->createMailbox([$this->agent]);
        $this->receiveEmail($second_mailbox, $this->makeEmail([
            'from' => 'other@customer.example.org', 'to' => $second_mailbox->email, 'subject' => 'Other question',
        ]));
        $counts = Sidebar::unreadCounts($this->agent);
        $this->assertSame(3, $counts['folders'][-Folder::TYPE_UNASSIGNED]);
        $this->assertSame(2, $counts['mailboxes'][$this->mailbox->id]);
        $this->assertSame(1, $counts['mailboxes'][$second_mailbox->id]);

        $html = $this->actingAs($this->agent)->get('/?dashboard=1')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#aria-label="Unassigned, 2 unread"\s+data-label-unread="Unassigned, :count unread"#', $html);
        $this->assertMatchesRegularExpression('#data-folder_id="'.$unassigned->id.'" data-unread-count="2">.*?<span class="f-badge active-count">2</span>#s', $html);
        $this->assertMatchesRegularExpression('#data-folder_id="-1" data-mailbox_id="-1" data-unread-count="3">.*?<span class="f-badge active-count">3</span>#s', $html);

        ConversationRead::markUnread($conversation->id, $this->agent);
        $this->assertSame(3, Sidebar::unreadCounts($this->agent)['folders'][$unassigned->id], 'Marking the conversation unread marks its messages unread.');
        $this->get($conversation->url())->assertOk();
        $this->assertSame(0, Sidebar::unreadCounts($this->agent)['folders'][$unassigned->id]);
    }

    public function testSidebarMovesUnreadMessagesWithTheirConversation()
    {
        $conversation = $this->conversation();
        $folder_ids = $this->mailbox->folders()->whereIn('type', [Folder::TYPE_UNASSIGNED, Folder::TYPE_MINE, Folder::TYPE_ASSIGNED, Folder::TYPE_STARRED, Folder::TYPE_CLOSED, Folder::TYPE_DELETED])->get()
            ->filter(fn ($folder) => !$folder->user_id || $folder->user_id == $this->agent->id)
            ->pluck('id', 'type');

        $conversation->changeUser($this->agent->id, $this->agent);
        $counts = Sidebar::unreadCounts($this->agent);
        $this->assertSame(0, $counts['folders'][$folder_ids[Folder::TYPE_UNASSIGNED]]);
        $this->assertSame(1, $counts['folders'][$folder_ids[Folder::TYPE_MINE]]);
        $this->assertSame(0, $counts['folders'][$folder_ids[Folder::TYPE_ASSIGNED]]);

        $conversation->star($this->agent);
        $this->assertSame(1, Sidebar::unreadCounts($this->agent)['folders'][$folder_ids[Folder::TYPE_STARRED]]);

        $conversation->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $counts = Sidebar::unreadCounts($this->agent);
        $this->assertSame(0, $counts['folders'][$folder_ids[Folder::TYPE_MINE]]);
        $this->assertSame(1, $counts['folders'][$folder_ids[Folder::TYPE_CLOSED]]);
        $this->assertSame(1, $counts['mailboxes'][$this->mailbox->id]);

        $conversation->deleteToFolder($this->agent);
        $counts = Sidebar::unreadCounts($this->agent);
        $this->assertSame(0, $counts['folders'][$folder_ids[Folder::TYPE_CLOSED]]);
        $this->assertSame(1, $counts['folders'][$folder_ids[Folder::TYPE_DELETED]]);
        $this->assertSame(1, $counts['folders'][$folder_ids[Folder::TYPE_STARRED]], 'The mailbox Starred list includes deleted conversations.');
        Folder::whereKey($folder_ids[Folder::TYPE_DELETED])->update(['total_count' => 0]);
        $this->assertStringContainsString('data-folder_id="'.$folder_ids[Folder::TYPE_DELETED].'"', $this->actingAs($this->agent)->get('/?dashboard=1')->assertOk()->getContent(), 'An unread folder stays visible before background totals catch up.');
    }

    /**
     * Conversations assigned to someone else don't concern the user: no folder (nor the mailbox)
     * counts them, until they're assigned to the user or to nobody.
     */
    public function testOthersConversationsAreNotCounted()
    {
        $conversation = $this->conversation();
        $conversation->star($this->agent);
        $conversation->changeUser($this->teammate->id, $this->teammate);
        foreach ([Conversation::STATUS_ACTIVE, Conversation::STATUS_CLOSED] as $status) {
            $conversation->changeStatus($status, $this->teammate);
            $counts = Sidebar::unreadCounts($this->agent);
            $this->assertSame(0, array_sum($counts['folders']), 'Status '.$status);
            $this->assertSame(0, $counts['mailboxes'][$this->mailbox->id]);
        }
        $conversation->deleteToFolder($this->teammate);
        $this->assertSame(0, array_sum(Sidebar::unreadCounts($this->agent)['folders']));

        // Unassigned again: counted.
        $other = $this->conversation('Another question');
        $other->changeUser($this->teammate->id, $this->teammate);
        $this->assertSame(0, Sidebar::unreadCounts($this->agent)['mailboxes'][$this->mailbox->id]);
        $other->changeUser(-1, $this->teammate);
        $this->assertGreaterThan(0, Sidebar::unreadCounts($this->agent)['mailboxes'][$this->mailbox->id]);
    }

    public function testStarredDraftsAndSentCountUnreadMessagesWithoutDuplicatingTheMailboxTotal()
    {
        $this->createMailbox([$this->agent]);
        $conversation = $this->conversation();
        $conversation->createUserThread($this->agent, 'My reply.');
        ConversationRead::markRead($conversation->id, $this->agent);
        $conversation->createUserThread($this->teammate, 'New note.', ['type' => Thread::TYPE_NOTE]);
        $conversation->star($this->agent);
        $conversation->addToFolder(Folder::TYPE_DRAFTS);

        $starred = $this->mailbox->folders()->where('type', Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();
        $drafts = $this->mailbox->folders()->where('type', Folder::TYPE_DRAFTS)->first();
        $counts = Sidebar::unreadCounts($this->agent);

        $this->assertSame(1, $counts['folders'][$starred->id]);
        $this->assertSame(1, $counts['folders'][$drafts->id]);
        $this->assertSame(1, $counts['folders'][-Folder::TYPE_STARRED]);
        $this->assertSame(1, $counts['folders'][-Folder::TYPE_DRAFTS]);
        $this->assertSame(1, $counts['folders'][-\App\Misc\AllMailboxes::TYPE_SENT]);
        $this->assertSame(1, $counts['mailboxes'][$this->mailbox->id]);

        $conversation->deleteToFolder($this->agent);
        $counts = Sidebar::unreadCounts($this->agent);
        $this->assertSame(1, $counts['folders'][$starred->id]);
        $this->assertSame(0, $counts['folders'][-Folder::TYPE_STARRED], 'All Mailboxes Starred excludes deleted conversations.');
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
        $list->call('rowRead', $first->id, 1)->assertSeeHtml('wire:click="rowRead('.$first->id.', 0)"')->assertDispatched('sidebar-counts');
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

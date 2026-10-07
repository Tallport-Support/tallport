<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Misc\AllMailboxes;
use App\User;
use Tests\FeatureTestCase;

/**
 * All Mailboxes' Drafts and Deleted folders, and what users who see only
 * their conversations get in them.
 */
class AllMailboxesFoldersTest extends FeatureTestCase
{
    protected $support;
    protected $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->createMailbox([], ['name' => 'Support']);
        $this->sales = $this->createMailbox([], ['name' => 'Sales']);
    }

    protected function conversation($mailbox, $subject)
    {
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function draft($user, $mailbox, $subject)
    {
        $saved = $this->postAjax($user, '/conversation/ajax', [
            'action' => 'save_draft', 'mailbox_id' => $mailbox->id, 'is_create' => 1, 'status' => Conversation::STATUS_ACTIVE,
            'to' => ['new.customer@customer.example.org'], 'subject' => $subject, 'body' => '<p>Not finished</p>',
        ])->json();

        return Conversation::find($saved['conversation_id']);
    }

    protected function ids(User $user, $type)
    {
        return AllMailboxes::query(AllMailboxes::folder($user, -$type), $user)->orderBy('conversations.id')->pluck('conversations.id')->all();
    }

    protected function restricted()
    {
        $user = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        foreach ([$this->support, $this->sales] as $mailbox) {
            $mailbox->users()->attach($user->id);
            $mailbox->syncPersonalFolders([$user->id]);
        }

        return $user->fresh();
    }

    public function testDraftsOfEveryMailbox()
    {
        $agent = $this->createUser();
        $this->support->users()->attach($agent->id);
        $this->sales->users()->attach($agent->id);
        $restricted = $this->restricted();

        $first = $this->draft($agent, $this->support, 'First draft');
        $second = $this->draft($agent, $this->sales, 'Second draft');
        $own = $this->draft($restricted, $this->sales, 'Own draft');

        $this->assertSame([$first->id, $second->id, $own->id], $this->ids($agent, Folder::TYPE_DRAFTS));
        $this->assertSame([$own->id], $this->ids($restricted, Folder::TYPE_DRAFTS), 'Their own drafts only.');
    }

    public function testDeletedOfEveryMailbox()
    {
        $admin = $this->createAdmin();
        $support = $this->conversation($this->support, 'Support question');
        $sales = $this->conversation($this->sales, 'Sales question');
        $this->conversation($this->sales, 'Kept');
        $support->deleteToFolder($admin);
        $sales->deleteToFolder($admin);

        $this->assertSame([$support->id, $sales->id], $this->ids($admin, Folder::TYPE_DELETED));
    }

    /**
     * Emptying Deleted in All Mailboxes deletes only the conversations the
     * user can see there.
     */
    public function testEmptyingDeletedDeletesOnlyTheirConversations()
    {
        $admin = $this->createAdmin();
        $restricted = $this->restricted();
        $theirs = $this->conversation($this->support, 'Theirs');
        $other = $this->conversation($this->sales, 'Other');
        $theirs->changeUser($restricted->id, $admin);
        $theirs->fresh()->deleteToFolder($admin);
        $other->deleteToFolder($admin);

        $this->assertTrue(AllMailboxes::emptyFolder($restricted, -Folder::TYPE_DELETED));

        $this->assertNull(Conversation::find($theirs->id));
        $this->assertNotNull(Conversation::find($other->id));
    }

    public function testSentFolderIcon()
    {
        $agent = $this->createUser();
        $this->support->users()->attach($agent->id);

        $this->assertSame('send', AllMailboxes::folder($agent, -AllMailboxes::TYPE_SENT)->getTypeIcon());
        $this->assertNotSame('send', AllMailboxes::folder($agent, -Folder::TYPE_DRAFTS)->getTypeIcon());
    }
}

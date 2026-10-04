<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Livewire\ConversationList;
use App\Misc\AllMailboxes;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The list of conversations (App\Livewire\ConversationList): sorting,
 * filtering by assignee, stars and the bulk actions.
 */
class ConversationListTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function conversation($subject, $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function folder($type)
    {
        return $this->mailbox->folders()->where('type', $type)->first();
    }

    public function testSortsAndFiltersByAssignee()
    {
        $banana = $this->conversation('Banana question');
        $apple = $this->conversation('Apple question');
        // Assigned: the conversations of the other agents.
        $sam = $this->createUser(['first_name' => 'Sam', 'last_name' => 'Second']);
        $kim = $this->createUser(['first_name' => 'Kim', 'last_name' => 'Third']);
        $this->mailbox->users()->attach([$sam->id, $kim->id]);
        $banana->changeUser($sam->id, $this->agent);
        $apple->changeUser($kim->id, $this->agent);
        $folder = $this->folder(Folder::TYPE_ASSIGNED);

        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSee('Banana question')->assertSee('Apple question');

        $list->call('sort', 'subject')->assertSeeInOrder(['Apple question', 'Banana question']);
        $list->call('sort', 'subject')->assertSeeInOrder(['Banana question', 'Apple question']);

        $list->call('filterAssignee', $sam->id)->assertSee('Banana question')->assertDontSee('Apple question');
        $list->call('filterAssignee')->assertSee('Apple question');
    }

    public function testFolderOpensAtAConversationInAWideWindow()
    {
        $older = $this->conversation('Older question');
        $newer = $this->conversation('Newer question');
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $url = route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $folder->id]);

        // Narrow: the list goes first.
        $this->actingAs($this->agent)->get($url)->assertOk()->assertSee('Newer question');

        // Wide: the first conversation, then the one last opened from the folder.
        $this->withUnencryptedCookie('tallport_narrow', '0');
        $first = $this->get($url)->headers->get('Location');
        $this->assertContains((int) basename(parse_url($first, PHP_URL_PATH)), [$older->id, $newer->id]);
        $this->get($older->url($folder->id))->assertOk();
        $this->get($url)->assertRedirect(route('conversations.view', ['id' => $older->id, 'folder_id' => $folder->id]));

        // An empty folder, and the All Mailboxes one.
        $this->get(route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_CLOSED)->id]))->assertOk();
        $this->get(route('mailboxes.all', ['folder_id' => -Folder::TYPE_UNASSIGNED]))->assertRedirect();
    }

    public function testStarsAConversation()
    {
        $conversation = $this->conversation('Starry question');
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)]);

        $list->call('star', $conversation->id)->assertSee('aria-pressed="true"', false);
        $this->assertTrue($conversation->isStarredByUser($this->agent->id));

        $list->call('star', $conversation->id);
        $this->assertFalse($conversation->isStarredByUser($this->agent->id));

        $outsider = $this->createUser();
        Livewire::actingAs($outsider)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->assertForbidden();
    }

    public function testBulkActions()
    {
        $first = $this->conversation('First question');
        $second = $this->conversation('Second question');
        $ids = [(string) $first->id, (string) $second->id];
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->set('selected', $ids)->assertSee('2 selected');

        $list->call('changeStatus', Conversation::STATUS_PENDING)->assertToasted('Status updated')->assertRedirect();
        $this->assertSame([Conversation::STATUS_PENDING, Conversation::STATUS_PENDING], [$first->fresh()->status, $second->fresh()->status]);

        $list->set('selected', $ids)->call('assign', $this->agent->id)->assertToasted('Assignee updated');
        $this->assertSame($this->agent->id, $first->fresh()->user_id);

        // Agents may not delete conversations by default.
        $list->set('selected', $ids)->call('delete')->assertToasted('Not enough permissions', 'danger');
        $this->assertSame(Conversation::STATE_PUBLISHED, $first->fresh()->state);

        $admin = $this->createAdmin();
        Livewire::actingAs($admin)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->set('selected', $ids)->call('delete')->assertToasted('Conversations deleted');
        $this->assertSame(Conversation::STATE_DELETED, $first->fresh()->state);
    }

    public function testAllMailboxesAndCustomerLists()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $support = $this->conversation('Support question');
        $this->conversation('Sales question', $sales);
        $folder = AllMailboxes::folder($this->agent, -Folder::TYPE_UNASSIGNED);

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder, 'params' => ['show_mailbox' => true]])
            ->assertSee('Support question')->assertSee('Sales question')
            ->call('sort', 'subject')->assertSeeInOrder(['Sales question', 'Support question']);

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['filter' => ['customer_id' => $support->customer_id], 'params' => ['no_checkboxes' => 1, 'no_customer' => 1]])
            ->call('gotoPage', 1)
            ->assertSee('Support question')->assertSee('Sales question')->assertDontSee('conv-checkbox', false);
    }
}

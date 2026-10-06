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

    public function testMineDoesNotNameTheAssignee()
    {
        $conversation = $this->conversation('Mine question');
        $conversation->changeUser($this->agent->id, $this->agent);

        // Mine is the viewer's own; elsewhere the assignee shows.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->assertSee('Mine question')->assertDontSeeHtml('conv-owner-name');
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => AllMailboxes::folder($this->agent, -Folder::TYPE_MINE)])
            ->assertSee('Mine question')->assertDontSeeHtml('conv-owner-name');
        $conversation->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_CLOSED)])
            ->assertSeeHtml('conv-owner-name')->assertSee('Alex Agent');
    }

    public function testFolderOpensAtAConversationInAWideWindow()
    {
        $older = $this->conversation('Older question');
        $newer = $this->conversation('Newer question');
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $url = route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $folder->id]);

        // Narrow: the list goes first.
        $this->actingAs($this->agent)->get($url)->assertOk()->assertSee('Newer question');

        // Wide: the first conversation, then the one last opened from the folder, shown at
        // the folder's URL (no redirect) with the conversation's own URL for the page.
        $this->withUnencryptedCookie('tallport_narrow', '0');
        $first = $this->get($url)->assertOk();
        $this->assertMatchesRegularExpression('#data-conversation_id="('.$older->id.'|'.$newer->id.')"#', $first->getContent());
        $this->get($older->url($folder->id))->assertOk()->assertDontSee('data-page-url', false);
        $this->get($url)->assertOk()->assertSee('data-conversation_id="'.$older->id.'"', false)
            ->assertSee('data-page-url="'.e(route('conversations.view', ['id' => $older->id, 'folder_id' => $folder->id])).'"', false);

        // An empty folder, and the All Mailboxes one.
        $this->get(route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_CLOSED)->id]))->assertOk();
        $this->get(route('mailboxes.all', ['folder_id' => -Folder::TYPE_UNASSIGNED]))->assertRedirect();
    }

    public function testColumnsOpenAtTheirResizedWidths()
    {
        $url = route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_UNASSIGNED)->id]);

        $this->withUnencryptedCookie('tallport_columns', json_encode(['--f-list-width' => 412, '--f-sidebar-width' => 5000, 'color' => 'red']));
        $this->actingAs($this->agent)->get($url)->assertOk()
            ->assertSee('style="--f-sidebar-width: 1000px; --f-list-width: 412px;"', false)
            ->assertDontSee('color: red', false);
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

    public function testAPrefetchedConversationIsSeenOnlyWhenShown()
    {
        $conversation = $this->conversation('Prefetched question');
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $notification = function () use ($conversation) {
            \DB::table('notifications')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'App\\Notifications\\WebsiteNotification', 'notifiable_id' => $this->agent->id,
                'notifiable_type' => \App\User::class, 'data' => '{}', 'conversation_id' => $conversation->id, 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $unread = fn () => \DB::table('notifications')->where('conversation_id', $conversation->id)->whereNull('read_at')->count();

        // Fetched by wire:navigate (maybe on hover): nothing happens yet.
        $notification();
        $this->actingAs($this->agent)->get($conversation->url($folder->id), ['X-Livewire-Navigate' => '1'])->assertOk();
        $this->assertSame(1, $unread());
        $this->assertNull(session('folder_conversation.'.$folder->id));

        // Shown: read, and the folder opens at it again.
        $this->postAjax($this->agent, '/conversation/ajax', ['action' => 'viewed', 'conversation_id' => $conversation->id, 'folder_id' => $folder->id])
            ->assertJson(['status' => 'success']);
        $this->assertSame(0, $unread());
        $this->assertSame($conversation->id, session('folder_conversation.'.$folder->id));

        // Loaded in full: at once.
        $notification();
        $this->actingAs($this->agent)->get($conversation->url($folder->id))->assertOk();
        $this->assertSame(0, $unread());

        $this->postAjax($this->createUser(), '/conversation/ajax', ['action' => 'viewed', 'conversation_id' => $conversation->id])
            ->assertJson(['status' => 'error']);
    }
}

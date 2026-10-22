<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Livewire\ConversationList;
use App\Misc\AllMailboxes;
use App\Misc\ConversationListQuery;
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

        // The user first, as Me.
        $list->assertSeeInOrder(['Anyone', 'Me', 'Kim Third'])->assertDontSee('Alex Agent');
        $list->call('filterAssignee', $sam->id)->assertSee('Banana question')->assertDontSee('Apple question');
        $list->call('filterAssignee')->assertSee('Apple question');
    }

    /**
     * One Filter menu: Unread, a status the folder's conversations can have, both, and Clear Filters.
     */
    public function testFiltersByUnreadAndStatus()
    {
        $read = $this->conversation('Read question');
        $unread = $this->conversation('Unread question');
        $pending = $this->conversation('Pending question');
        $unread_pending = $this->conversation('Waiting question');
        \App\ConversationRead::markRead([$read->id, $pending->id], $this->agent);
        $pending->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $unread_pending->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);

        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSee('Read question')->assertSee('Unread question')
            ->assertSee('aria-label="Filter"', false)->assertSee('wire:click="filterUnread"', false)
            // Unassigned has no closed conversations: no Closed to choose.
            ->assertSee('wire:click="filterShow('.Conversation::STATUS_PENDING.')"', false)
            ->assertDontSee('wire:click="filterShow('.Conversation::STATUS_CLOSED.')"', false)
            ->assertDontSee('Clear Filters');

        $list->call('filterUnread')->assertSee('Unread question')->assertSee('Waiting question')->assertDontSee('Read question')
            ->assertSee('aria-label="Filter: Unread"', false)->assertSee('Clear Filters');
        $list->call('filterShow', Conversation::STATUS_PENDING)->assertSee('Waiting question')->assertDontSee('Unread question')->assertDontSee('Pending question')
            ->assertSee('aria-label="Filter: Unread, Pending"', false);
        $list->call('filterUnread')->assertSee('Pending question')->assertSee('Waiting question')->assertDontSee('Unread question');
        $list->call('filterShow', Conversation::STATUS_CLOSED)->assertDontSee('question')->assertSee('There are no conversations here')->assertSee('conv-list__filter', false);
        $list->call('clearFilters')->assertSee('Read question')->assertSee('Unread question')->assertSee('Pending question');
        $list->call('filterShow', 'nonsense')->assertSee('Read question')->assertSee('aria-label="Filter"', false);

        // Closed: all closed, only Unread to choose.
        $read->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_CLOSED)])
            ->assertSee('wire:click="filterUnread"', false)->assertDontSee('Any Status');
    }

    public function testOneListQueryDoesNotChangeAnothersParameters()
    {
        $first = $this->conversation('First question');
        $second = $this->conversation('Second question');
        $sam = $this->createUser();
        $kim = $this->createUser();
        $this->mailbox->users()->attach([$sam->id, $kim->id]);
        $first->changeUser($sam->id, $this->agent);
        $second->changeUser($kim->id, $this->agent);
        $folder = $this->folder(Folder::TYPE_ASSIGNED);
        $this->actingAs($this->agent);
        request()->merge(['folder_id' => 0, 'params' => ['user_id' => $sam->id]]);

        $query = app(ConversationListQuery::class);
        $sam_list = $query->listConversations(['folder_id' => $folder->id, 'params' => ['user_id' => $sam->id], 'page' => 1], $this->agent);
        $kim_list = $query->listConversations(['folder_id' => $folder->id, 'params' => ['user_id' => $kim->id], 'page' => 1], $this->agent);

        $this->assertSame([$first->id], $sam_list['conversations']->pluck('id')->all());
        $this->assertSame([$second->id], $kim_list['conversations']->pluck('id')->all());
        $this->assertSame(['user_id' => $sam->id], request()->params);
        $this->assertSame(0, request()->folder_id);
    }

    /**
     * The conversation number in search results only (it stays through sorting, paging
     * and realtime updates).
     */
    public function testNumberInSearchResultsOnly()
    {
        $conversation = $this->conversation('Banana question');
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSee('Banana question')->assertDontSee('conv-number');

        $results = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder, 'params' => ['show_number' => true]])
            ->assertSee('<span class="conv-number">#'.$conversation->number.'</span>', false);
        $results->call('sort', 'subject')->assertSee('<span class="conv-number">#'.$conversation->number.'</span>', false);
        $results->call('gotoPage', 1)->assertSee('conv-number');
        $results->dispatch('conversations-changed')->assertSee('conv-number');
    }

    /**
     * Mine shows the latest activity first (an assignment or a note counts); other folders
     * keep their own date (Waiting Since); a chosen order is remembered per kind of folder.
     */
    public function testMineByLastActivityAndTheChosenOrderIsRemembered()
    {
        $older = $this->conversation('Older question');
        $newer = $this->conversation('Newer question');
        $older->changeUser($this->agent->id, $this->agent);
        $newer->changeUser($this->agent->id, $this->agent);
        \DB::table('conversations')->where('id', $older->id)->update(['last_reply_at' => now()->subHours(14), 'last_activity_at' => now()->subHours(14)]);
        \DB::table('conversations')->where('id', $newer->id)->update(['last_reply_at' => now()->subHour(), 'last_activity_at' => now()->subHour()]);

        // A colleague's note on the older one: on top of Mine, though nobody replied.
        $colleague = $this->createUser(['first_name' => 'Sam']);
        $this->mailbox->users()->attach($colleague->id);
        $this->sendReply($colleague, [
            'mailbox_id' => $this->mailbox->id, 'conversation_id' => $older->id, 'body' => '<p>Over to you</p>', 'is_note' => 1,
        ]);

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->assertSet('sorting', ['sort_by' => 'activity', 'order' => 'desc'])
            ->assertSeeInOrder(['Older question', 'Newer question'])->assertSee('Last Activity');

        // Waiting Since there: the newer reply first; the choice is kept for Mine only.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->call('sort', 'date')->assertSet('sorting', ['sort_by' => 'date', 'order' => 'desc'])
            ->assertSeeInOrder(['Newer question', 'Older question']);
        $this->assertSame(['sort_by' => 'date', 'order' => 'desc'], \App\Conversation::savedSorting($this->agent->fresh())[Folder::TYPE_MINE]);
        Livewire::actingAs($this->agent->fresh())->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->assertSet('sorting', ['sort_by' => 'date', 'order' => 'desc']);
        Livewire::actingAs($this->agent->fresh())->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_ASSIGNED)])
            ->assertSet('sorting', ['sort_by' => 'date', 'order' => 'desc']);
    }

    /**
     * Date Sent: the latest message's Date header (newest first, then the
     * other way), its time on the rows; remembered like the other orders.
     */
    public function testSortsByDateSent()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Sent late', 'date' => now()->subHour()->format('r'),
        ]));
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Sent early', 'date' => now()->subDays(3)->format('r'),
        ]));
        // Received in the other order.
        \DB::table('conversations')->where('subject', 'Sent late')->update(['last_reply_at' => now()->subDay()]);
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);

        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->assertSeeInOrder(['Sent early', 'Sent late'])
            ->call('sort', 'sent')->assertSet('sorting', ['sort_by' => 'sent', 'order' => 'desc'])
            ->assertSeeInOrder(['Date Sent', 'Sent late', 'Sent early'])
            ->assertSee('3d');
        $this->assertSame(['sort_by' => 'sent', 'order' => 'desc'], Conversation::savedSorting($this->agent->fresh())[Folder::TYPE_UNASSIGNED]);
        $list->call('sort', 'sent')->assertSet('sorting', ['sort_by' => 'sent', 'order' => 'asc'])
            ->assertSeeInOrder(['Sent early', 'Sent late']);

        Livewire::actingAs($this->agent->fresh())->test(ConversationList::class, ['folder' => $folder])
            ->assertSet('sorting', ['sort_by' => 'sent', 'order' => 'asc'])
            ->assertSeeInOrder(['Sent early', 'Sent late']);
    }

    public function testMineDoesNotNameTheAssignee()
    {
        $conversation = $this->conversation('Mine question');
        $conversation->changeUser($this->agent->id, $this->agent);

        // One mailbox: no color bar (every row would have it).
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->assertDontSeeHtml('data-fruit-mark');

        // The viewer's own never names them; someone else's names its assignee.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])
            ->assertSee('Mine question')->assertDontSeeHtml('conv-owner-name');
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => AllMailboxes::folder($this->agent, -Folder::TYPE_MINE)])
            ->assertSee('Mine question')->assertDontSeeHtml('conv-owner-name');
        $conversation->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_CLOSED)])
            ->assertSee('Mine question')->assertDontSeeHtml('conv-owner-name');
        $other = $this->createUser(['first_name' => 'Blair', 'last_name' => 'Colleague']);
        $this->mailbox->users()->attach($other->id);
        $conversation->changeUser($other->id, $this->agent);
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_CLOSED)])
            ->assertSeeHtml('conv-owner-name')->assertSee('Blair Colleague');
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
        // the folder's URL (no redirect) with the conversation beside that folder for the page.
        $this->withUnencryptedCookie('tallport_narrow', '0');
        $first = $this->get($url)->assertOk();
        $this->assertMatchesRegularExpression('#data-conversation_id="('.$older->id.'|'.$newer->id.')"#', $first->getContent());
        $this->get($older->url($folder->id))->assertOk()->assertSee('data-page-url="'.e(\App\Misc\Addresses::conversationUrl($older->folder, $older)).'"', false);
        $this->get($url)->assertOk()->assertSee('data-conversation_id="'.$older->id.'"', false)
            ->assertSee('data-page-url="'.e(\App\Misc\Addresses::conversationUrl($folder, $older)).'"', false);

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

    /**
     * Rows have no star: a conversation is starred from its header, and found in Starred.
     */
    public function testRowsHaveNoStar()
    {
        $conversation = $this->conversation('Starry question');
        $conversation->star($this->agent);

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->assertSee('Starry question')->assertDontSee('conv-star', false)->assertDontSee('Star Conversation');
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_STARRED)])
            ->assertSee('Starry question')->assertDontSee('conv-star', false);
    }

    public function testBulkActions()
    {
        $first = $this->conversation('First question');
        $second = $this->conversation('Second question');
        $ids = [(string) $first->id, (string) $second->id];
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->set('selected', $ids)->assertSee('2 selected')
            // The same status: its dot, named; each status in the menu leads with its dot.
            ->assertSeeHtml('aria-label="Status: Active"')->assertSeeHtml('f-badge--warning conv-status-dot');
        // ...and checked in the menu.
        $this->assertMatchesRegularExpression('/aria-checked="true"[^>]*data-status="'.Conversation::STATUS_ACTIVE.'"/', $list->html());

        $first->setStatus(Conversation::STATUS_CLOSED);
        $first->save();
        $list->set('selected', [$ids[0]])->set('selected', $ids)
            // Different statuses: a ring, just "Status".
            ->assertSeeHtml('conv-status-dot--mixed')->assertDontSeeHtml('aria-label="Status: Active"');
        $this->assertDoesNotMatchRegularExpression('/aria-checked="true"[^>]*data-status=/', $list->html());

        // Shown anew in place (no reload): the counters and the open conversation follow.
        $list->call('changeStatus', Conversation::STATUS_PENDING)->assertToasted('Status updated')->assertNoRedirect()
            ->assertSet('selected', [])->assertDispatched('sidebar-counts')
            ->assertDispatched('conversation-changed', ids: [$first->id, $second->id]);
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

    /**
     * A chat's or phone call's row leads its meta line with the channel's icon, named for
     * screen readers; email, the usual, has none.
     */
    public function testChannelToken()
    {
        $email = $this->conversation('Email question');
        $chat = $this->conversation('Chat question');
        $chat->channel = \App\Telegram\Telegram::CHANNEL;
        $chat->threads_count = 6;
        $chat->save();
        $phone = $this->conversation('Phone question');
        $phone->type = Conversation::TYPE_PHONE;
        $phone->save();

        $html = preg_replace('/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/', '', Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])->html());
        // A chat's and a phone call's icon, not email's (the usual), and no count.
        $this->assertSame(2, substr_count($html, 'class="conv-channel"'));
        $this->assertStringContainsString('<span class="conv-channel" title="Telegram"><svg', $html);
        $this->assertStringContainsString('<span class="f-sr-only">Telegram</span>', $html);
        $this->assertStringContainsString('<span class="f-sr-only">Phone</span>', $html);
        $this->assertStringNotContainsString('<span aria-hidden="true">6</span>', $html);
        // The email's row has nothing else to say: no meta line.
        $this->assertSame(2, substr_count($html, 'conv-row__meta'));
        $this->assertStringNotContainsString('f-badge conv-channel', $html);
    }

    /**
     * A row's context menu: on a row alone, on a selected row (the whole selection), and on a
     * row outside the selection (that row, the selection kept).
     */
    public function testRowMenu()
    {
        $first = $this->conversation('First question');
        $second = $this->conversation('Second question');
        $third = $this->conversation('Third question');
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)]);
        // One menu for every row (tallportRowMenu), reading what it acts on from the rows.
        $list->assertSeeHtml('aria-label="Conversation Actions"')->assertSeeHtml('data-fruit-targets="li.conv-row"')
            ->assertSeeInOrder(['Open in New Tab', 'Copy Link', 'Star', 'Assign to Me', 'Close'])->assertDontSee('Actions for')
            ->assertSeeHtml('wire:click="rowStar(target.dataset.conversation_id, menuAll(target, \'starred\') ? 0 : 1)"');
        $this->assertSame(1, substr_count($list->html(), 'f-context-menu'));
        $this->assertStringContainsString('data-url="'.e($third->url()).'"', $list->html());

        $list->call('rowStar', $first->id, 1)->assertToasted('Starred')->assertNoRedirect()->assertDispatched('conversation-changed', ids: [$first->id]);
        $this->assertTrue($first->isStarredByUser($this->agent->id));
        $this->assertFalse($second->isStarredByUser($this->agent->id));
        $this->assertMatchesRegularExpression('#data-conversation_id="'.$first->id.'"[^>]*data-starred#', Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])->html());

        // A selected row: the selection (its title from the server, for its plural).
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->set('selected', [(string) $first->id, (string) $second->id])
            ->assertSeeHtml('data-selection-title="Actions for 2 conversations"');
        $list->call('rowStar', $second->id, 1);
        $this->assertTrue($second->isStarredByUser($this->agent->id));
        $this->assertFalse($third->isStarredByUser($this->agent->id));

        $list->set('selected', [(string) $first->id, (string) $second->id])->call('rowClose', $first->id, 1)->assertToasted('Status updated');
        $this->assertSame([Conversation::STATUS_CLOSED, Conversation::STATUS_CLOSED, Conversation::STATUS_ACTIVE], [$first->fresh()->status, $second->fresh()->status, $third->fresh()->status]);

        // A row outside the selection: that row alone; the selection stays.
        $list->set('selected', [(string) $first->id, (string) $second->id])->call('rowAssignToMe', $third->id)->assertToasted('Assignee updated')
            ->assertSet('selected', [(string) $first->id, (string) $second->id]);
        // Closing the first made the agent responsible for it (Conversation::takeOwnership()).
        $this->assertSame([$this->agent->id, $this->agent->id], [$first->fresh()->user_id, $third->fresh()->user_id]);
        $list->set('selected', [])->call('rowStar', $first->id, 0)->call('rowClose', $third->id, 0);
        $this->assertFalse($first->isStarredByUser($this->agent->id));
        $this->assertTrue($second->isStarredByUser($this->agent->id));

        // Not in lists without selection (a customer's conversations).
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['filter' => ['customer_id' => $first->customer_id], 'params' => ['no_checkboxes' => 1, 'no_customer' => 1]])
            ->call('gotoPage', 1)->assertDontSeeHtml('f-context-menu');
    }

    /**
     * A row shows its status at the end of its meta line unless it's Active, and not in
     * folders where every row has the same status.
     */
    public function testStatusOtherThanActiveIsShown()
    {
        $active = $this->conversation('Active question');
        $pending = $this->conversation('Pending question');
        foreach ([$active, $pending] as $conversation) {
            $conversation->changeUser($this->agent->id, $this->agent);
        }
        $pending->changeStatus(Conversation::STATUS_PENDING, $this->agent);

        $html = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_MINE)])->html();
        $this->assertSame(1, substr_count($html, 'conv-row__status'));
        $this->assertMatchesRegularExpression('#<span class="conv-row__status">.*?f-badge--warning conv-status-dot.*?Pending</span>#s', $html);

        $pending->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_CLOSED)])
            ->assertSee('Pending question')->assertDontSeeHtml('conv-row__status');
    }

    public function testAllMailboxesAndCustomerLists()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales', 'accent' => 'orange']);
        $support = $this->conversation('Support question');
        $this->conversation('Sales question', $sales);
        $folder = AllMailboxes::folder($this->agent, -Folder::TYPE_UNASSIGNED);

        // Across mailboxes: each row's mailbox as a bar in its color, its name for screen readers only.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder, 'params' => ['show_mailbox' => true]])
            ->assertSeeHtml('data-fruit-mark="orange"')->assertSeeHtml('Sales mailbox')
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

    /**
     * Embedded (x_embed), rows open in a new tab, also after the list changes.
     */
    public function testEmbeddedRowsOpenInANewTab()
    {
        $this->conversation('Embedded question');

        Livewire::withQueryParams(['x_embed' => 1])->actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])
            ->assertSet('params.target_blank', true)
            ->call('sort', 'subject')->assertSeeHtml('target="_blank"');
    }

    /**
     * Another page keeps the page's address in step (its page parameter).
     */
    public function testPagesKeepTheAddress()
    {
        $this->conversation('Paged question');

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED), 'pageParam' => 'list_page'])
            ->call('gotoPage', 0)->assertSet('page', 1)
            ->call('gotoPage', 2)->assertSet('page', 2)->assertSet('selected', []);
        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED), 'pageParam' => 'list_page'])
            ->call('gotoPage', 2);
        $this->assertStringContainsString('url.searchParams.set("list_page", 2)', $list->effects['xjs'][0]['expression']);
    }

    public function testAnUnknownStatusOrSortChangesNothing()
    {
        $conversation = $this->conversation('Question');

        $list = Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)]);
        $sorting = $list->get('sorting');
        $list->call('sort', 'customer')->assertSet('sorting', $sorting);
        $list
            ->set('selected', [(string) $conversation->id])->call('changeStatus', 99)->assertToasted('Incorrect status', 'danger')->assertNoRedirect();
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    public function testSignedOutUsersGetNothing()
    {
        Livewire::test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])->assertUnauthorized();
    }
}

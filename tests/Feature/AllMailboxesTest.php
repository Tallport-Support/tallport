<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Misc\AllMailboxes;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * All Mailboxes: the folders of every mailbox of a user together, first in
 * the sidebar, with each mailbox below it.
 */
class AllMailboxesTest extends FeatureTestCase
{
    protected $agent;
    protected $support;
    protected $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->support = $this->createMailbox([$this->agent], ['name' => 'Support']);
        $this->sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
    }

    protected function conversation($mailbox, $subject)
    {
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    public function testUnassignedOfEveryMailbox()
    {
        $support = $this->conversation($this->support, 'Support question');
        $sales = $this->conversation($this->sales, 'Sales question');
        $hidden_mailbox = $this->createMailbox([], ['name' => 'Hidden']);
        $this->conversation($hidden_mailbox, 'Hidden question');

        $this->actingAs($this->agent)->get('/')->assertRedirect(route('mailboxes.all'));
        $this->get('/?dashboard=1')->assertOk()->assertSee('Dashboard');

        $response = $this->get(route('mailboxes.all'))->assertOk()
            ->assertSee('Support question')->assertSee('Sales question')->assertDontSee('Hidden question')
            ->assertSee('All Mailboxes');
        // Each mailbox below All Mailboxes, folded.
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'data-mailbox_id="'.$this->support->id.'"'), strpos($html, 'data-mailbox_id="-1"'));
        $this->assertMatchesRegularExpression('#data-folder_id="-1" data-mailbox_id="-1">.*?<span class="f-badge active-count">2</span>#s', $html, 'Unassigned counts both mailboxes.');
        $this->assertMatchesRegularExpression('#<details class="f-sidebar__group app-sidebar__mailbox" data-mailbox_id="'.$this->sales->id.'"\s*>#', $html);

        // Next pages.
        $page = $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'conversations_pagination', 'mailbox_id' => AllMailboxes::MAILBOX_ID, 'folder_id' => -Folder::TYPE_UNASSIGNED, 'page' => 1,
            'params' => ['show_mailbox' => 1],
        ]);
        $this->assertStringContainsString('Sales question', $page->json('html'));
        $this->assertStringContainsString('Support question', $page->json('html'));

        // In a mailbox, the tree has it open.
        $this->assertMatchesRegularExpression('#data-mailbox_id="'.$this->sales->id.'"\s+open\s*>#', $this->get(route('mailboxes.view', ['id' => $this->sales->id]))->assertOk()->getContent());
    }

    /**
     * Like a mail app: a conversation opened in All Mailboxes stays there.
     */
    public function testConversationOpenedInAllMailboxes()
    {
        $older = $this->conversation($this->support, 'Support question');
        $newer = $this->conversation($this->sales, 'Sales question');
        Conversation::where('id', $older->id)->update(['last_reply_at' => now()->subHour()]);
        $folder_id = -Folder::TYPE_UNASSIGNED;

        $page = $this->actingAs($this->agent)->get($newer->url($folder_id))->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('#aria-current="page"\s+data-folder_id="'.$folder_id.'" data-mailbox_id="-1"#', $html);
        $this->assertDoesNotMatchRegularExpression('#data-mailbox_id="'.$this->sales->id.'"\s+open#', $html);
        // Older and newer across the mailboxes, still in All Mailboxes.
        $page->assertSee('href="'.$older->url($folder_id).'" class="f-button f-button--ghost f-button--icon" title="Older"', false);
        $this->get($older->url($folder_id))->assertOk()->assertSee('href="'.$newer->url($folder_id).'" class="f-button f-button--ghost f-button--icon" title="Newer"', false);

        // Back to the list after an action.
        $this->get(route('mailboxes.view.folder', ['id' => $this->sales->id, 'folder_id' => $folder_id]))
            ->assertRedirect(route('mailboxes.all', ['folder_id' => $folder_id]));
    }

    public function testMineAssignedStarredSent()
    {
        $colleague = $this->createUser();
        $this->support->users()->attach($colleague->id);
        $mine = $this->conversation($this->support, 'Mine');
        $theirs = $this->conversation($this->sales, 'Theirs');
        $mine->changeUser($this->agent->id, $this->agent);
        $theirs->changeUser($colleague->id, $this->agent);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->sales->id, 'conversation_id' => $theirs->id, 'body' => '<p>Answer</p>',
        ]);
        Conversation::find($mine->id)->star($this->agent);
        $this->assertSame(1, Thread::where('conversation_id', $theirs->id)->where('type', Thread::TYPE_MESSAGE)->count(), 'The reply was sent.');

        $ids = function ($type) {
            return AllMailboxes::query(AllMailboxes::folder($this->agent, -$type), $this->agent)->pluck('conversations.id')->all();
        };
        $this->actingAs($this->agent);
        $this->assertSame([$mine->id], $ids(Folder::TYPE_MINE));
        $this->assertSame([$theirs->id], $ids(Folder::TYPE_ASSIGNED));
        $this->assertSame([$mine->id], $ids(Folder::TYPE_STARRED));
        $this->assertSame([$theirs->id], $ids(AllMailboxes::TYPE_SENT));

        $this->get(route('mailboxes.all', ['folder_id' => -AllMailboxes::TYPE_SENT]))->assertOk()->assertSee('data-conversation_id="'.$theirs->id.'"', false)->assertDontSee('data-conversation_id="'.$mine->id.'"', false);
        $this->get(route('mailboxes.all', ['folder_id' => -999]))->assertNotFound();
    }

    public function testEmptyTrashOfEveryMailbox()
    {
        $admin = $this->createAdmin();
        $first = $this->conversation($this->support, 'First');
        $second = $this->conversation($this->sales, 'Second');
        $first->deleteToFolder($admin);
        $second->deleteToFolder($admin);

        $this->postAjax($admin, '/conversation/ajax', [
            'action' => 'empty_folder', 'mailbox_id' => AllMailboxes::MAILBOX_ID, 'folder_id' => -Folder::TYPE_DELETED,
        ])->assertJsonPath('status', 'success');

        $this->assertNull(Conversation::find($first->id));
        $this->assertNull(Conversation::find($second->id));
        $this->postAjax($admin, '/conversation/ajax', [
            'action' => 'empty_folder', 'mailbox_id' => AllMailboxes::MAILBOX_ID, 'folder_id' => -Folder::TYPE_CLOSED,
        ])->assertJsonPath('status', 'error');
    }

    public function testUsersWhoSeeOnlyTheirConversations()
    {
        $restricted = $this->createUser();
        $this->support->users()->attach($restricted->id);
        $this->sales->users()->attach($restricted->id);
        $restricted->permissions = [User::PERM_ONLY_ASSIGNED_TICKETS => true];
        $restricted->save();

        $mine = $this->conversation($this->support, 'Assigned to me');
        $this->conversation($this->sales, 'Not mine');
        $mine->changeUser($restricted->id, $restricted);

        $this->assertTrue($restricted->fresh()->canSeeOnlyAssignedConversations());
        $this->actingAs($restricted->fresh())->get(route('mailboxes.all'))->assertDontSee('Not mine');
        $this->get(route('mailboxes.all', ['folder_id' => -Folder::TYPE_MINE]))->assertSee('Assigned to me');
    }

    public function testSettingsPagesListTheSettingsInTheSidebar()
    {
        $admin = $this->createAdmin();
        $this->support->users()->attach($admin->id);

        // Settings: back to the inbox, the account, the app's settings and Manage; no mailboxes.
        $html = $this->actingAs($admin)->get(route('settings', ['section' => 'emails']))->assertOk()->getContent();
        $sidebar = substr($html, strpos($html, 'id="app-sidebar"'));
        $this->assertStringContainsString('app-sidebar__back', $sidebar);
        $this->assertStringContainsString('<a aria-current="page" class="f-sidebar__item" href="'.route('settings', ['section' => 'emails']).'">', $sidebar);
        $this->assertStringContainsString('app-sidebar__account-card', $sidebar);
        $this->assertStringNotContainsString('app-sidebar__account-pages', $sidebar, 'The account\'s pages show while one is open.');
        $this->assertStringContainsString('app-sidebar__account-pages', $this->get(route('users.profile', ['id' => $admin->id]))->getContent());
        $this->assertStringNotContainsString('app-sidebar__mailbox', $sidebar);

        // A mailbox's settings are under Manage > Mailboxes; an agent sees only the account.
        $html = $this->get(route('mailboxes.update', ['id' => $this->support->id]))->getContent();
        $this->assertStringContainsString('app-sidebar__back', $html);
        $this->assertStringContainsString('<a class="f-sidebar__item" href="'.route('mailboxes.permissions', ['id' => $this->support->id]).'"', $html);
        $html = $this->actingAs($this->agent)->get(route('users.preferences', ['id' => $this->agent->id]))->assertOk()->getContent();
        $this->assertStringContainsString('app-sidebar__back', $html);
        $this->assertStringNotContainsString(route('settings', ['section' => 'general']), $html);

        // Elsewhere the mailboxes, as ever.
        $this->assertStringNotContainsString('app-sidebar__back', $this->get(route('mailboxes.view', ['id' => $this->support->id]))->getContent());
    }

    public function testMailboxButtonsAreOnTheHeadings()
    {
        $admin = $this->createAdmin();
        $html = $this->actingAs($admin)->get(route('mailboxes.view', ['id' => $this->support->id]))->assertOk()->getContent();

        foreach ([$this->support, $this->sales] as $mailbox) {
            $this->assertStringContainsString(route('conversations.create', ['mailbox_id' => $mailbox->id]), $html);
            $this->assertStringContainsString(route('mailboxes.update', ['id' => $mailbox->id]), $html);
        }
        // The keyboard shortcut's New Conversation: the open mailbox's.
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('conversations.create', ['mailbox_id' => $this->support->id]), '#').'" class="[^"]*new-conversation-link#', $html);
        $this->assertSame(1, substr_count($html, 'new-conversation-link'));
    }

    public function testOneMailboxNoTree()
    {
        $single = $this->createUser();
        $this->support->users()->attach($single->id);

        $this->actingAs($single)->get('/')->assertOk();
        $this->get(route('mailboxes.all'))->assertRedirect();
        $this->get(route('mailboxes.view', ['id' => $this->support->id]))->assertOk()->assertDontSee('All Mailboxes');
    }

    public function testMailboxMenu()
    {
        $this->actingAs($this->agent)->get(route('mailboxes.view', ['id' => $this->support->id]))
            ->assertSee('class="app-sidebar__brand" href="'.route('dashboard', ['dashboard' => 1]).'"', false)
            ->assertSee('id="app-sidebar"', false);
    }
}

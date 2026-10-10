<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationInspector;
use App\Folder;
use App\Livewire\ConversationList;
use App\Livewire\ConversationListToolbar;
use App\Livewire\ConversationPane;
use App\Livewire\ConversationToolbar;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Another conversation opens in place (conversation-open): the column, toolbar and
 * customer switch to it, and it counts as viewed, without loading the page.
 */
class ConversationOpenInPlaceTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function conversation($subject, $from)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $from, 'to' => $this->mailbox->email, 'subject' => $subject]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    public function testTheColumnToolbarAndCustomerSwitch()
    {
        $first = $this->conversation('Broken zipper', 'Casey Customer <casey@customer.example.org>');
        $second = $this->conversation('Lost parcel', 'Sam Shopper <sam@customer.example.org>');
        \DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'App\\Notifications\\WebsiteNotification', 'notifiable_id' => $this->agent->id,
            'notifiable_type' => \App\User::class, 'data' => '{}', 'conversation_id' => $second->id, 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pane = Livewire::actingAs($this->agent)->test(ConversationPane::class, ['conversation' => $first, 'folder' => $first->folder])
            ->assertSee('Broken zipper')
            ->dispatch('conversation-open', id: $second->id, folder_id: $first->folder_id)
            ->assertSee('Lost parcel')->assertDontSee('Broken zipper')
            ->assertDispatched('conversation-opened', id: $second->id, url: route('conversations.view', ['id' => $second->id]))
            // No build of the styles to compare where minifying is off (tests).
            ->assertDispatched('conversation-opened', styles: null)
            // It was unread: the list follows (its dot goes).
            ->assertDispatched('conversations-changed');
        $this->assertSame([], \App\ConversationRead::unreadIds([$second], $this->agent));
        // Read already: nothing for the list.
        $pane->dispatch('conversation-open', id: $first->id, folder_id: $first->folder_id);
        $pane->dispatch('conversation-open', id: $second->id, folder_id: $first->folder_id)->assertNotDispatched('conversations-changed');
        $this->assertSame(0, \DB::table('notifications')->where('conversation_id', $second->id)->whereNull('read_at')->count());
        $this->assertSame($second->id, session('folder_conversation.'.$first->folder_id));

        Livewire::actingAs($this->agent)->test(ConversationToolbar::class, ['conversation' => $first])
            ->dispatch('conversation-open', id: $second->id)->assertSet('conversation_id', $second->id);
        // The customer's address copies on a click.
        Livewire::actingAs($this->agent)->test(ConversationInspector::class, ['conversation' => $first])
            ->assertSee('casey@customer.example.org')
            ->assertSeeHtml('aria-label="Copy: casey@customer.example.org" x-data x-on:click="copyToClipboard(')
            ->dispatch('conversation-open', id: $second->id)->assertSee('sam@customer.example.org');

        // Someone else's conversation: nothing changes.
        $other = $this->createMailbox();
        $this->receiveEmail($other, $this->makeEmail(['from' => 'pat@customer.example.org', 'to' => $other->email, 'subject' => 'Not yours']));
        $pane->dispatch('conversation-open', id: Conversation::where('mailbox_id', $other->id)->first()->id)
            ->assertSet('conversation_id', $second->id);
    }

    public function testADraftOpensInItsOwnPage()
    {
        $first = $this->conversation('Broken zipper', 'casey@customer.example.org');
        $draft = $this->conversation('Draft question', 'sam@customer.example.org');
        $draft->state = Conversation::STATE_DRAFT;
        $draft->save();

        Livewire::actingAs($this->agent)->test(ConversationPane::class, ['conversation' => $first])
            ->dispatch('conversation-open', id: $draft->id)->assertRedirect();
    }

    public function testTheListMarksTheOpenConversation()
    {
        $first = $this->conversation('Broken zipper', 'casey@customer.example.org');
        $second = $this->conversation('Lost parcel', 'sam@customer.example.org');

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $first->folder, 'mailbox' => $this->mailbox, 'params' => ['current_conversation_id' => $first->id]])
            ->dispatch('conversation-open', id: $second->id)
            ->assertSet('params.current_conversation_id', $second->id);
    }

    public function testAnotherFolderOpensInPlace()
    {
        $unassigned = $this->conversation('Broken zipper', 'casey@customer.example.org');
        $mine = $this->conversation('Lost parcel', 'sam@customer.example.org');
        $mine->user_id = $this->agent->id;
        $mine->save();
        $this->mailbox->updateFoldersCounters();
        $mine_folder = $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();
        $closed_folder = $this->mailbox->folders()->where('type', Folder::TYPE_CLOSED)->first();

        // The list, its toolbar and the column follow, at the folder's conversation.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $unassigned->folder, 'mailbox' => $this->mailbox, 'params' => ['current_conversation_id' => $unassigned->id]])
            ->dispatch('folder-open', folder_id: $mine_folder->id)
            ->assertSet('folder_id', $mine_folder->id)->assertSet('params.current_conversation_id', $mine->id)
            ->assertSee('Lost parcel')->assertDontSee('Broken zipper');
        Livewire::actingAs($this->agent)->test(ConversationListToolbar::class, ['folder' => $unassigned->folder])
            ->dispatch('folder-open', folder_id: $mine_folder->id)->assertSee($mine_folder->getTypeName());
        Livewire::actingAs($this->agent)->test(ConversationPane::class, ['conversation' => $unassigned, 'folder' => $unassigned->folder])
            ->dispatch('folder-open', folder_id: $mine_folder->id)
            ->assertSee('Lost parcel')->assertDispatched('conversation-opened', id: $mine->id, folder_id: $mine_folder->id)
            // An empty folder: its page.
            ->dispatch('folder-open', folder_id: $closed_folder->id)
            ->assertRedirect($closed_folder->url($this->mailbox->id));

        // Someone else's folder: nothing.
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $unassigned->folder, 'mailbox' => $this->mailbox])
            ->dispatch('folder-open', folder_id: $this->createMailbox()->folders()->first()->id)
            ->assertSet('folder_id', $unassigned->folder_id);
    }

    /**
     * The toolbar follows a folder opened in place; a folder the user can't
     * see, or an empty one, leaves it as it is. All Mailboxes' folders count too.
     */
    public function testTheToolbarFollowsAnotherFolder()
    {
        $unassigned = $this->conversation('Broken zipper', 'casey@customer.example.org');
        $mine = $this->conversation('Lost parcel', 'sam@customer.example.org');
        $mine->user_id = $this->agent->id;
        $mine->save();
        $this->mailbox->updateFoldersCounters();
        $mine_folder = $this->mailbox->folders()->where('type', Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();
        $closed_folder = $this->mailbox->folders()->where('type', Folder::TYPE_CLOSED)->first();

        $toolbar = Livewire::actingAs($this->agent)->test(ConversationToolbar::class, ['conversation' => $unassigned, 'folder' => $unassigned->folder])
            ->dispatch('folder-open', folder_id: $mine_folder->id)
            ->assertSet('conversation_id', $mine->id)->assertSet('folder_id', $mine_folder->id);
        $toolbar->dispatch('folder-open', folder_id: $closed_folder->id)->assertSet('conversation_id', $mine->id);
        $toolbar->dispatch('folder-open', folder_id: $this->createMailbox()->folders()->first()->id)->assertSet('folder_id', $mine_folder->id);

        // All Mailboxes' Mine (with a second mailbox).
        $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $all_mine = \App\Misc\AllMailboxes::folder($this->agent, -Folder::TYPE_MINE);
        $toolbar->dispatch('folder-open', folder_id: $all_mine->id, conversation_id: $mine->id)->assertSet('folder_id', $all_mine->id);

        // The list's toolbar: not someone else's folder.
        Livewire::actingAs($this->agent)->test(ConversationListToolbar::class, ['folder' => $unassigned->folder])
            ->dispatch('folder-open', folder_id: $this->createMailbox()->folders()->first()->id)->assertSet('folder_id', $unassigned->folder_id);
    }
}

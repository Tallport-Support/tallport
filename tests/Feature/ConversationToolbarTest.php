<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationSubject;
use App\Livewire\ConversationToolbar;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The open conversation's toolbar (App\Livewire\ConversationToolbar) and
 * heading (App\Livewire\ConversationSubject).
 */
class ConversationToolbarTest extends FeatureTestCase
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

    protected function toolbar($user = null)
    {
        return Livewire::actingAs($user ?: $this->agent)->test(ConversationToolbar::class, ['conversation' => $this->conversation]);
    }

    public function testAssignsAndChangesTheStatus()
    {
        // The current status checked in the menu.
        $this->assertMatchesRegularExpression('/aria-checked="true"[^>]*data-status="'.$this->conversation->status.'"/', $this->toolbar()->html());
        // In place (shown at once in the browser): the conversation, the list and the counters follow.
        $this->toolbar()->call('assign', $this->agent->id)->assertNoRedirect()->assertToasted('Assignee updated')
            ->assertDispatched('conversation-changed', ids: [$this->conversation->id])->assertDispatched('conversations-changed')
            ->assertDispatched('sidebar-counts');
        $this->assertSame($this->agent->id, $this->conversation->fresh()->user_id);
        $this->assertNull(session('flash_success_floating'), 'No page load to show it on.');

        // Refused: the list shown as it is (the browser's change undone).
        $this->toolbar()->call('assign', $this->agent->id)->assertToasted('Assignee already set', 'danger')->assertNoRedirect()
            ->assertDispatched('conversations-changed')->assertNotDispatched('conversation-changed');

        $this->toolbar()->call('changeStatus', Conversation::STATUS_SPAM)->assertToasted('Status updated');
        $this->assertSame(Conversation::STATUS_SPAM, $this->conversation->fresh()->status);
        $this->toolbar()->call('changeStatus', 'not_spam');
        $this->assertSame(Conversation::STATUS_ACTIVE, $this->conversation->fresh()->status);
    }

    /**
     * Deleted from a folder the user is viewing (Mine, though their conversations are in Assigned):
     * on to the next there, in place; the last one, back to that folder.
     */
    public function testDeleteStaysInTheFolderBeingViewed()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Sam Shopper <sam@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Lost parcel',
        ]));
        $other = Conversation::where('mailbox_id', $this->mailbox->id)->where('id', '!=', $this->conversation->id)->first();
        foreach ([$this->conversation, $other] as $conversation) {
            $conversation->changeUser($this->agent->id, $this->agent);
        }
        $this->agent->permissions = [\App\User::PERM_DELETE_CONVERSATIONS => true];
        $this->agent->save();
        $mine = $this->mailbox->folders()->where('type', \App\Folder::TYPE_MINE)->where('user_id', $this->agent->id)->first();
        $toolbar = fn ($conversation) => Livewire::actingAs($this->agent)->test(ConversationToolbar::class, ['conversation' => $conversation->fresh(), 'folder' => $mine]);

        $toolbar($this->conversation)->call('delete')->assertNoRedirect()->assertToasted('Conversation deleted')
            ->assertDispatched('conversation-next', id: $other->id)->assertDispatched('conversations-changed');
        $this->assertSame(Conversation::STATE_DELETED, $this->conversation->fresh()->state);

        $toolbar($other)->call('delete')->assertRedirect($mine->url($this->mailbox->id));
    }

    /**
     * Closed where the user goes on (not staying in a conversation): the next one, opened in place.
     */
    public function testOnToTheNextConversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Sam Shopper <sam@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Lost parcel',
        ]));
        $next = Conversation::where('mailbox_id', $this->mailbox->id)->where('id', '!=', $this->conversation->id)->first();
        $this->agent->stay_in_conversation = false;
        $this->agent->save();

        $this->toolbar()->call('changeStatus', Conversation::STATUS_CLOSED)->assertNoRedirect()
            ->assertDispatched('conversation-next', id: $next->id);
    }

    public function testTheHeadingNamesTheChannel()
    {
        // The page's text, without Livewire's markers.
        $page = fn () => preg_replace('#<!--.*?-->#s', '', $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()->getContent());

        // Email: the mailbox's address.
        $this->assertStringContainsString($this->mailbox->name.' · '.$this->mailbox->email, $page());

        // A channel (its chat view): led by the customer's picture and name, then the
        // channel and the mailbox; no Cc/Bcc or Merge.
        $this->conversation->channel = \App\Telegram\Telegram::CHANNEL;
        $this->conversation->save();
        $html = $page();
        $this->assertStringContainsString('Telegram · '.$this->mailbox->name, $html);
        $this->assertStringContainsString('f-avatar conv-heading__avatar', $html);
        $this->assertStringNotContainsString($this->mailbox->name.' · '.$this->mailbox->email, $html);
        $this->assertStringNotContainsString('id="toggle-cc"', $html);
        $this->assertTrue($this->conversation->hasChannel());
    }

    public function testFollowsAndDeletes()
    {
        $this->toolbar()->call('follow', true)->assertReturned(true)->assertToasted('Following')->assertDispatched('conversation-followed', following: true);
        $this->assertTrue($this->conversation->fresh()->isUserFollowing($this->agent->id));
        $this->toolbar()->call('follow', false)->assertToasted('Unfollowed');
        $this->assertFalse($this->conversation->fresh()->isUserFollowing($this->agent->id));

        // Agents may not delete conversations by default.
        $this->toolbar()->call('delete')->assertToasted('Not enough permissions', 'danger');

        $admin = $this->createAdmin();
        $this->toolbar($admin)->call('delete')->assertRedirect();
        $this->assertSame(Conversation::STATE_DELETED, $this->conversation->fresh()->state);

        $this->toolbar($admin)->assertSee('Restore')->call('restore')->assertRedirect($this->conversation->fresh()->url());
        $this->assertSame(Conversation::STATE_PUBLISHED, $this->conversation->fresh()->state);

        $this->conversation->deleteToFolder($admin);
        $this->toolbar($admin)->assertSee('Delete Forever')->call('delete')->assertRedirect();
        $this->assertNull(Conversation::find($this->conversation->id));
    }

    public function testOutsiderIsForbidden()
    {
        Livewire::actingAs($this->createUser())->test(ConversationToolbar::class, ['conversation' => $this->conversation])->assertForbidden();
        Livewire::actingAs($this->createUser())->test(ConversationSubject::class, ['conversation' => $this->conversation])->assertForbidden();
    }

    public function testStarsAndRenames()
    {
        $heading = Livewire::actingAs($this->agent)->test(ConversationSubject::class, ['conversation' => $this->conversation])
            ->assertSee('Broken zipper')->assertSee('aria-pressed="false"', false);

        // The sidebar's unread Starred count follows (public/js/tallport.js).
        $starred = $this->mailbox->folders()->where('type', \App\Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();
        $heading->call('star')->assertSee('aria-pressed="true"', false)->assertToasted('Starred')
            ->assertDispatched('sidebar-counts');
        $this->assertSame(1, \App\Misc\Sidebar::unreadCounts($this->agent)['folders'][$starred->id]);
        $this->assertTrue($this->conversation->isStarredByUser($this->agent->id));
        $heading->call('star')->assertToasted('Unstarred')->assertDispatched('sidebar-counts');
        $this->assertSame(0, \App\Misc\Sidebar::unreadCounts($this->agent)['folders'][$starred->id]);
        $heading->call('star');

        $heading->call('saveSubject', 'Zipper replaced')->assertSee('Zipper replaced');
        $this->assertSame('Zipper replaced', $this->conversation->fresh()->subject);

        $this->agent->followConversation($this->conversation->id);
        $heading->dispatch('conversation-followed', following: true)->assertSee('conv-following', false);
    }
}

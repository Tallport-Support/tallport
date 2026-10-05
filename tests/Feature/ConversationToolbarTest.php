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
        $this->toolbar()->assertSee('data-status', false)
            ->call('assign', $this->agent->id)->assertRedirect($this->conversation->url());
        $this->assertSame($this->agent->id, $this->conversation->fresh()->user_id);
        $this->assertStringStartsWith('Assignee updated', session('flash_success_floating'));

        $this->toolbar()->call('assign', $this->agent->id)->assertToasted('Assignee already set', 'danger')->assertNoRedirect();

        $this->toolbar()->call('changeStatus', Conversation::STATUS_SPAM)->assertRedirect();
        $this->assertSame(Conversation::STATUS_SPAM, $this->conversation->fresh()->status);
        $this->toolbar()->call('changeStatus', 'not_spam')->assertRedirect();
        $this->assertSame(Conversation::STATUS_ACTIVE, $this->conversation->fresh()->status);
    }

    public function testTheHeadingNamesTheChannel()
    {
        // The page's text, without Livewire's markers.
        $page = fn () => preg_replace('#<!--.*?-->#s', '', $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()->getContent());

        // Email: the mailbox's address.
        $this->assertStringContainsString($this->mailbox->name.' · '.$this->mailbox->email, $page());

        // A channel: its name instead, and no Cc/Bcc or Merge.
        $this->conversation->channel = \App\Telegram\Telegram::CHANNEL;
        $this->conversation->save();
        $html = $page();
        $this->assertStringContainsString($this->mailbox->name.' · Telegram', $html);
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

        $heading->call('star')->assertSee('aria-pressed="true"', false);
        $this->assertTrue($this->conversation->isStarredByUser($this->agent->id));

        $heading->call('saveSubject', 'Zipper replaced')->assertSee('Zipper replaced');
        $this->assertSame('Zipper replaced', $this->conversation->fresh()->subject);

        $this->agent->followConversation($this->conversation->id);
        $heading->dispatch('conversation-followed', following: true)->assertSee('conv-following', false);
    }
}

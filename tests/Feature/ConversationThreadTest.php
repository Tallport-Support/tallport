<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationThread;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The open conversation's history (App\Livewire\ConversationThread): message
 * kinds, editing in place, deleting notes and new messages.
 */
class ConversationThreadTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->admin]);
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Broken zipper', 'body' => 'My zipper broke.',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
    }

    protected function note($body)
    {
        $this->postAjax($this->admin, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id,
            'body'   => $body, 'is_note' => 1,
        ]);

        return $this->conversation->threads()->where('type', Thread::TYPE_NOTE)->orderBy('id', 'desc')->first();
    }

    protected function thread()
    {
        return Livewire::actingAs($this->admin)->test(ConversationThread::class, ['conversation' => $this->conversation]);
    }

    public function testShowsTheHistoryAndNewMessages()
    {
        $thread = $this->thread()->assertSee('My zipper broke.')->assertSee('id="conv-layout-main"', false)
            ->assertDontSee('f-message--outgoing', false);

        $this->note('<p>Checked the warranty.</p>');
        $thread->dispatch('conversation-thread-created')->assertSee('Checked the warranty.')->assertSee('f-message--note', false);

        Livewire::actingAs($this->createUser())->test(ConversationThread::class, ['conversation' => $this->conversation])->assertForbidden();
    }

    public function testEditsInPlaceAndDeletesNotes()
    {
        $note = $this->note('<p>First thought</p>');
        $thread = $this->thread();

        $thread->call('edit', $note->id)->assertSet('editing', $note->id)->assertSee('thread-editor-'.$note->id, false);
        $thread->call('saveEdit', $note->id, '')->assertToasted('Message cannot be empty', 'danger');
        $thread->call('saveEdit', $note->id, '<p>Second thought</p>')->assertSet('editing', null)
            ->assertSee('Second thought')->assertSee('Show Original');
        $this->assertStringContainsString('First thought', $note->fresh()->body_original);

        $thread->call('deleteNote', $note->id)->assertDontSee('Second thought');
        $this->assertNull(Thread::find($note->id));

        $customer_thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $thread->call('deleteNote', $customer_thread->id)->assertToasted('Thread not found', 'danger');
    }

    public function testLinksKeepThePageAddress()
    {
        $note = $this->note('<p>A note</p>');
        $this->actingAs($this->admin)->get('/conversation/'.$this->conversation->id.'?search=parcel')
            ->assertSee('search=parcel&amp;print_thread_id='.$note->id, false);
    }

    /**
     * A reply that failed is sent again from its message (Retry); a message
     * of another conversation isn't found.
     */
    public function testRetriesAFailedReply()
    {
        $this->postAjax($this->admin, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id, 'body' => '<p>Our answer</p>',
        ]);
        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        \DB::table('jobs')->where('queue', 'emails')->delete();
        $reply->send_status = \App\SendLog::STATUS_SEND_ERROR;
        $reply->updateSendStatusData(['msg' => '550 Mailbox unavailable']);
        $reply->save();

        $sent = count($this->sentEmailsTo('casey@customer.example.org'));

        $this->thread()->call('retry', $reply->id)->assertNotDispatched('fruit-toast');

        $this->assertCount($sent + 1, $this->sentEmailsTo('casey@customer.example.org'));
        $reply->refresh();
        $this->assertSame(\App\SendLog::STATUS_ACCEPTED, (int) $reply->send_status);
        $this->assertSame('', $reply->getSendStatusData()['msg'] ?? '');

        $other = $this->createMailbox([$this->admin]);
        $this->receiveEmail($other, $this->makeEmail(['from' => 'pat@customer.example.org', 'to' => $other->email]));
        $elsewhere = Conversation::where('mailbox_id', $other->id)->first()->threads()->first();
        $this->thread()->call('retry', $elsewhere->id)->assertToasted('Thread not found', 'danger');
    }
}

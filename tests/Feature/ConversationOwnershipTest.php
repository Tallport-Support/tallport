<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationList;
use App\Workflows\Runner;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Closed, marked as spam or deleted, a conversation always has someone responsible
 * (Conversation::takeOwnership()): with nobody assigned, the person doing it.
 */
class ConversationOwnershipTest extends FeatureTestCase
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

    protected function conversation($subject = 'Question')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => $subject,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    public function testClosingSpamAndDeletingTakeOwnership()
    {
        foreach ([Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM] as $status) {
            $conversation = $this->conversation();
            $conversation->changeStatus($status, $this->agent);
            $this->assertSame($this->agent->id, (int) $conversation->fresh()->user_id, 'Status '.$status);
        }

        $conversation = $this->conversation();
        $conversation->deleteToFolder($this->agent);
        $this->assertSame($this->agent->id, (int) $conversation->fresh()->user_id);

        // Pending or active: nobody is assigned for it.
        $conversation = $this->conversation();
        $conversation->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $this->assertNull($conversation->fresh()->user_id);
    }

    public function testSomeoneAssignedStays()
    {
        $conversation = $this->conversation();
        $conversation->changeUser($this->teammate->id, $this->teammate);
        $conversation->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $conversation->deleteToFolder($this->agent);

        $this->assertSame($this->teammate->id, (int) $conversation->fresh()->user_id);
    }

    public function testRepliesBulkActionsAndWorkflows()
    {
        // Send & Close, leaving it unassigned.
        $replied = $this->conversation('Replied');
        $this->sendReply($this->agent, [
            'conversation_id' => $replied->id, 'mailbox_id' => $this->mailbox->id,
            'body' => '<p>Done.</p>', 'status' => Conversation::STATUS_CLOSED, 'user_id' => -1,
        ]);
        $this->assertSame($this->agent->id, (int) $replied->fresh()->user_id);

        // The list's bulk actions.
        $first = $this->conversation('First');
        $second = $this->conversation('Second');
        $folder = $this->mailbox->folders()->where('type', \App\Folder::TYPE_UNASSIGNED)->first();
        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->set('selected', [$first->id, $second->id])->call('changeStatus', Conversation::STATUS_CLOSED);
        $this->assertSame([$this->agent->id, $this->agent->id], [(int) $first->fresh()->user_id, (int) $second->fresh()->user_id]);

        // An automatic workflow (its robot): nobody to take it.
        $automatic = $this->conversation('Automatic');
        $automatic->changeStatus(Conversation::STATUS_CLOSED, Runner::robot());
        $this->assertNull($automatic->fresh()->user_id);
    }
}

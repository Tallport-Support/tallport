<?php

namespace Tests\Feature;

use App\Conversation;
use App\Misc\ConversationActions;
use App\Thread;
use Illuminate\Http\Request;
use Tests\FeatureTestCase;

/**
 * ConversationActions refuse a missing conversation or thread, and users
 * without access to the conversation (the Livewire components call them
 * directly, without the ajax endpoint's checks).
 */
class ConversationActionsGuardsTest extends FeatureTestCase
{
    public function testMissingConversationOrThread()
    {
        $user = $this->createUser();
        $not_found = ['msg' => 'Conversation not found'];

        $this->assertSame($not_found, ConversationActions::changeUser(null, $user->id, $user, new Request()));
        $this->assertSame($not_found, ConversationActions::restore(null, $user));
        $this->assertSame($not_found, ConversationActions::follow(null, $user));
        $this->assertSame($not_found, ConversationActions::delete(null, $user));
        $this->assertSame($not_found, ConversationActions::changeSubject(null, 'New subject', $user));
        $this->assertSame(['msg' => 'Thread not found'], ConversationActions::retrySend(null, $user));
    }

    public function testUserWithoutAccess()
    {
        $mailbox = $this->createMailbox();
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Question']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $thread = $conversation->threads()->first();
        $outsider = $this->createUser(['permissions' => [\App\User::PERM_DELETE_CONVERSATIONS => true]]);
        $refused = ['msg' => 'Not enough permissions'];

        $this->assertSame($refused, ConversationActions::changeUser($conversation, $outsider->id, $outsider, new Request()));
        $this->assertSame($refused, ConversationActions::restore($conversation, $outsider));
        $this->assertSame($refused, ConversationActions::follow($conversation, $outsider));
        $this->assertSame($refused, ConversationActions::delete($conversation, $outsider));
        $this->assertSame($refused, ConversationActions::changeSubject($conversation, 'New subject', $outsider));
        $this->assertSame($refused, ConversationActions::retrySend($thread, $outsider));

        $conversation = $conversation->fresh();
        $this->assertSame('Question', $conversation->subject);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->state);
        $this->assertNull($conversation->user_id);
        $this->assertSame(0, \App\Follower::where('conversation_id', $conversation->id)->count());
    }

    /**
     * Assigning to oneself from an embedded page (x_embed) stays on the conversation.
     */
    public function testAssigningInAnEmbeddedPageStaysThere()
    {
        $agent = $this->createUser();
        $colleague = $this->createUser();
        $mailbox = $this->createMailbox([$agent, $colleague]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $this->actingAs($agent);
        $url = $conversation->url();

        $result = ConversationActions::changeUser($conversation, $colleague->id, $agent, new Request(['x_embed' => 1]));

        $this->assertSame('success', $result['status']);
        $this->assertSame($url, $result['redirect_url'], 'The conversation, as it was in the folder the agent is in.');
        $this->assertSame($colleague->id, $conversation->fresh()->user_id);
        $this->assertSame(Thread::ACTION_TYPE_USER_CHANGED, $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->value('action_type'));
    }
}

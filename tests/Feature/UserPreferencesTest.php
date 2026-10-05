<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\MailboxUser;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * A user's own preferences: the status a reply leaves and where the user goes
 * after sending, for every mailbox.
 */
class UserPreferencesTest extends FeatureTestCase
{
    public function testSavedAndUsedWhenReplying()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Broken zipper',
        ]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();

        $this->actingAs($agent)->get(route('users.preferences', ['id' => $agent->id]))->assertOk()
            ->assertSee('Status After a Reply')->assertSee('Next active conversation');
        $this->actingAs($agent)->get(route('users.preferences', ['id' => $this->createUser()->id]))->assertForbidden();

        \Session::start();
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'reply_status' => Conversation::STATUS_CLOSED, 'after_send' => MailboxUser::AFTER_SEND_STAY,
        ])->assertRedirect(route('users.preferences', ['id' => $agent->id]));
        $agent->refresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $agent->reply_status);

        // The reply: Send & Close, then this conversation again, closed.
        $composer = Livewire::actingAs($agent)->test(ConversationComposer::class, ['conversation' => $conversation])
            ->call('open', 'reply')->assertSet('status', Conversation::STATUS_CLOSED)->assertSee('Send &amp; Close', false);
        $composer->set('body', '<p>Fixed.</p>')->call('send')->assertRedirect();
        $this->assertStringContainsString('/conversation/'.$conversation->id.'?', $composer->effects['redirect']);
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);

        // Unset: Pending, then the next active conversation.
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'reply_status' => '', 'after_send' => '',
        ]);
        $this->assertNull($agent->fresh()->reply_status);
        $this->assertSame(Conversation::STATUS_PENDING, $agent->fresh()->replyStatus());
        $this->assertSame(MailboxUser::AFTER_SEND_NEXT, (int) $agent->fresh()->afterSend());
    }
}

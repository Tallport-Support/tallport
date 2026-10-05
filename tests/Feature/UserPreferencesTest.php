<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\MailboxUser;
use App\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * A user's own preferences: the status a reply leaves and where the user goes
 * after sending, for every mailbox; the accent; each channel's conversation view.
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

        // An accent of one's own, else the installation's.
        \Option::set('branding.accent', 'green');
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'pink']);
        $this->assertSame('pink', $agent->fresh()->accent);
        $this->actingAs($agent->fresh())->get(route('users.preferences', ['id' => $agent->id]))->assertSee('data-fruit-accent="pink"', false);
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'pink', 'accent_default' => 1]);
        $this->assertNull($agent->fresh()->accent);
        $this->actingAs($agent->fresh())->get(route('users.preferences', ['id' => $agent->id]))->assertSee('data-fruit-accent="green"', false);
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'neon'])->assertSessionHasErrors('accent');

        // Unset: Pending, then the next active conversation.
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'reply_status' => '', 'after_send' => '',
        ]);
        $this->assertNull($agent->fresh()->reply_status);
        $this->assertSame(Conversation::STATUS_PENDING, $agent->fresh()->replyStatus());
        $this->assertSame(MailboxUser::AFTER_SEND_NEXT, (int) $agent->fresh()->afterSend());
    }

    public function testConversationViewPerChannel()
    {
        $agent = $this->createUser();
        $telegram = \App\Telegram\Telegram::CHANNEL;

        // Defaults: email as email, channels as chats.
        $this->assertSame(User::VIEW_EMAIL, $agent->conversationView());
        $this->assertSame(User::VIEW_CHAT, $agent->conversationView($telegram));
        $this->actingAs($agent)->get(route('users.preferences', ['id' => $agent->id]))->assertOk()
            ->assertSee('Conversation View')->assertSee('name="conversation_views['.$telegram.']"', false);

        \Session::start();
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'conversation_views' => ['email' => User::VIEW_CHAT, $telegram => User::VIEW_EMAIL, 12345 => User::VIEW_CHAT],
        ])->assertSessionHasNoErrors();
        $agent->refresh();
        $this->assertSame(User::VIEW_CHAT, $agent->conversationView());
        $this->assertSame(User::VIEW_EMAIL, $agent->conversationView($telegram));
        $this->assertArrayNotHasKey(12345, json_decode($agent->conversation_views, true), 'Only known channels are kept.');

        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'conversation_views' => ['email' => 'columns'],
        ])->assertSessionHasErrors('conversation_views.email');
    }
}

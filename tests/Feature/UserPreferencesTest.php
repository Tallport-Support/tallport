<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Misc\ConversationActions;
use App\User;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * A user's own preferences: language, timezone, time format and keyboard shortcuts;
 * the status a reply leaves and where the user goes after sending, for every mailbox;
 * the accent.
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

        $this->actingAs($agent)->get(route('own.preferences'))->assertOk()
            ->assertSee('Status After a Reply')->assertSee('When a Conversation Moves')->assertSee('Stay in Folder');
        $this->actingAs($agent)->get(route('users.preferences', ['id' => $this->createUser()->id]))->assertForbidden();

        \Session::start();
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'reply_status' => Conversation::STATUS_CLOSED, 'stay_in_conversation' => 1,
        ])->assertRedirect(route('own.preferences'));
        $agent->refresh();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $agent->reply_status);

        // The reply: Send & Close, then this conversation again, closed (in Closed: stayed in).
        $composer = Livewire::actingAs($agent)->test(ConversationComposer::class, ['conversation' => $conversation])
            ->call('open', 'reply')->assertSet('status', Conversation::STATUS_CLOSED)->assertSee('Send &amp; Close', false);
        $composer->set('body', '<p>Fixed.</p>')->call('send')->assertRedirect();
        $this->assertSame(url('/conversation/'.$conversation->id), $composer->effects['redirect']);
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);

        // An accent of one's own, else the installation's.
        \Option::set('branding.accent', 'green');
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'pink']);
        $this->assertSame('pink', $agent->fresh()->accent);
        $this->actingAs($agent->fresh())->get(route('own.preferences'))->assertSee('data-fruit-accent="pink"', false);
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'pink', 'accent_default' => 1]);
        $this->assertNull($agent->fresh()->accent);
        $this->actingAs($agent->fresh())->get(route('own.preferences'))->assertSee('data-fruit-accent="green"', false);
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), ['_token' => csrf_token(), 'accent' => 'neon'])->assertSessionHasErrors('accent');

        // Unset: Pending, and staying in the folder.
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'reply_status' => '', 'stay_in_conversation' => '',
        ]);
        $this->assertNull($agent->fresh()->reply_status);
        $this->assertSame(Conversation::STATUS_PENDING, $agent->fresh()->replyStatus());
        $this->assertFalse($agent->fresh()->stay_in_conversation);
    }

    /**
     * Only a conversation leaving the folder the user is in takes them away from it: to the
     * folder's next conversation (Stay in Folder, the default), or along with it (Stay in Conversation).
     */
    public function testWhenAConversationMoves()
    {
        $agent = $this->createUser();
        $colleague = $this->createUser();
        $mailbox = $this->createMailbox([$agent, $colleague]);
        foreach (['Broken zipper', 'Late parcel'] as $subject) {
            $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => $subject]));
        }
        $zipper = Conversation::where('subject', 'Broken zipper')->first();
        $parcel = Conversation::where('subject', 'Late parcel')->first();
        $unassigned = $mailbox->folders()->where('type', \App\Folder::TYPE_UNASSIGNED)->first();
        $this->actingAs($agent);

        // Pending, then a reply: still in Unassigned, so the agent stays on it.
        $result = ConversationActions::changeStatus($parcel, Conversation::STATUS_PENDING, $agent, new Request());
        $this->assertSame($parcel->url(), $result['redirect_url']);
        $response = $this->sendReply($agent, ['mailbox_id' => $mailbox->id, 'conversation_id' => $parcel->id, 'body' => '<p>On its way.</p>', 'status' => Conversation::STATUS_PENDING]);
        $this->assertSame($parcel->url(), $response['redirect_url']);

        // Assigned to a colleague, it leaves Unassigned: the next one there.
        $result = ConversationActions::changeUser($parcel->fresh(), $colleague->id, $agent, new Request());
        $this->assertSame($zipper->url(), $result['redirect_url']);

        // Taking one is working on it: the agent stays on it, though it moved to Mine.
        $result = ConversationActions::changeUser($zipper, $agent->id, $agent, new Request());
        $this->assertSame($zipper->url(), $result['redirect_url']);

        // Sent and closed, the last one in Mine: the folder.
        $mine = $mailbox->folders()->where('type', \App\Folder::TYPE_MINE)->where('user_id', $agent->id)->first();
        $response = $this->sendReply($agent, ['mailbox_id' => $mailbox->id, 'conversation_id' => $zipper->id, 'body' => '<p>Fixed.</p>', 'status' => Conversation::STATUS_CLOSED]);
        $this->assertSame($mine->url($mailbox->id), $response['redirect_url']);

        // Staying in the conversation: along with it, back to Unassigned.
        $agent->stay_in_conversation = true;
        $agent->save();
        $result = ConversationActions::changeUser($parcel->fresh(), -1, $agent, new Request());
        $this->assertSame($parcel->url(), $result['redirect_url']);
        $this->assertSame($unassigned->id, $parcel->fresh()->folder_id);
    }

    public function testLanguageTimezoneAndTimeFormat()
    {
        $agent = $this->createUser(['locale' => 'en', 'timezone' => 'Europe/Amsterdam', 'time_format' => User::TIME_FORMAT_24]);

        // On the preferences, not the profile.
        $this->actingAs($agent)->get(route('own.preferences'))->assertOk()
            ->assertSee('name="locale"', false)->assertSee('name="timezone"', false)->assertSee('name="time_format"', false)
            ->assertSee('name="keyboard_shortcuts"', false)->assertSee('name="languages[]"', false);
        $this->actingAs($agent)->get(route('own.profile'))->assertOk()
            ->assertDontSee('name="locale"', false)->assertDontSee('name="timezone"', false)->assertDontSee('name="time_format"', false)
            ->assertDontSee('name="keyboard_shortcuts"', false)->assertDontSee('name="languages[]"', false);
        // Settings' search finds them on Preferences.
        $keywords = \App\Misc\Sidebar::settingsKeywords('users/preferences');
        foreach (['language', 'timezone', 'keyboard shortcuts', 'no translation needed'] as $keyword) {
            $this->assertStringContainsString($keyword, $keywords);
        }

        \Session::start();
        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'locale' => 'nl', 'timezone' => 'America/New_York', 'time_format' => User::TIME_FORMAT_12,
        ])->assertRedirect(route('own.preferences'))->assertSessionHasNoErrors();
        $agent->refresh();
        $this->assertSame('nl', $agent->locale);
        $this->assertSame('America/New_York', $agent->timezone);
        $this->assertSame(User::TIME_FORMAT_12, (int) $agent->time_format);
        // The new language right away.
        $this->assertSame('nl', session('user_locale'));
        $this->assertSame('Instellingen bijgewerkt', session('flash_success_floating'));
        $this->actingAs($agent->fresh())->get(route('own.preferences'))->assertSee('Voorkeuren');

        // Saved as it changes, in the background: a new language reloads the page, nothing else does.
        $this->assertTrue($this->postAjax($agent->fresh(), route('users.preferences.save', ['id' => $agent->id]), ['locale' => 'de'])->json('reload'));
        $this->assertFalse($this->postAjax($agent->fresh(), route('users.preferences.save', ['id' => $agent->id]), ['locale' => 'de', 'time_format' => User::TIME_FORMAT_12])->json('reload'));
        $this->postAjax($agent->fresh(), route('users.preferences.save', ['id' => $agent->id]), ['locale' => 'nl']);

        $this->actingAs($agent)->post(route('users.preferences.save', ['id' => $agent->id]), [
            '_token' => csrf_token(), 'locale' => 'xx', 'time_format' => 7,
        ])->assertSessionHasErrors(['locale', 'time_format']);
        $this->assertSame('nl', $agent->fresh()->locale);
    }
}

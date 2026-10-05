<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Events\RealtimeTeamMessage;
use App\Livewire\TeamChat;
use App\Notifications\TeamMentionNotification;
use App\Notifications\WebsiteNotification;
use App\TeamMessage;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * A mailbox's team chat: one room for the users who can see the mailbox, with
 * unread counts in the sidebar, mentions as notifications and links to
 * conversations.
 */
class TeamChatTest extends FeatureTestCase
{
    protected $ann;
    protected $bob;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ann = $this->createUser(['first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->bob = $this->createUser(['first_name' => 'Bob', 'last_name' => 'Ray']);
        $this->mailbox = $this->createMailbox([$this->ann, $this->bob]);
    }

    public function testTheRoomIsForThoseWhoSeeTheMailbox()
    {
        $this->actingAs($this->ann)->get(route('mailboxes.team_chat', ['id' => $this->mailbox->id]))->assertOk()
            ->assertSee('Support Team')->assertSee('Ann, Bob')->assertSee('team-room', false)
            ->assertSee('data-fruit-trigger="@"', false)->assertSee('<option value="@Bob">Bob Ray</option>', false);

        $outsider = $this->createUser();
        $this->actingAs($outsider)->get(route('mailboxes.team_chat', ['id' => $this->mailbox->id]))->assertForbidden();
    }

    public function testMessagesAreStoredEncrypted()
    {
        Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->set('body', 'The refund went out.')->call('send')->assertSet('body', '')
            ->assertSee('The refund went out.');

        $stored = \DB::table('team_messages')->where('mailbox_id', $this->mailbox->id)->value('body');
        $this->assertStringNotContainsString('refund', $stored);
        $this->assertSame('The refund went out.', TeamMessage::where('mailbox_id', $this->mailbox->id)->first()->body);
    }

    public function testUnreadCountsAndTheNewDivider()
    {
        TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Morning.']);
        TeamMessage::markRead($this->mailbox->id, $this->bob->id);
        $new = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Anyone on #42?']);
        TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Never mind.']);

        // Others' messages count, not one's own.
        $this->assertSame([$this->mailbox->id => 2], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $this->assertSame([], TeamMessage::unreadCounts($this->ann, [$this->mailbox->id]));
        $this->actingAs($this->bob)->get(route('mailboxes.view', ['id' => $this->mailbox->id]))->assertOk()
            ->assertSee('aria-label="Support Team Chat, 2 unread"', false);

        // Opening the room: "New" from the first unread, and read.
        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->assertSet('first_new_id', $new->id)->assertSeeHtml('f-divider--accent')->assertSeeHtml('aria-label="New Messages"');
        $this->assertSame([], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
    }

    public function testLinksAndMentions()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Broken zipper']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $other = Conversation::where('mailbox_id', '!=', $this->mailbox->id)->value('number') ?: 999999;
        $members = TeamMessage::members($this->mailbox);

        $html = TeamMessage::html("#{$conversation->number} and #{$other} for @bob, not @nobody <b>", $this->mailbox, $this->ann, $members);
        $this->assertStringContainsString('<a href="'.e($conversation->url()).'" title="Broken zipper">#'.$conversation->number.'</a>', $html);
        $this->assertStringContainsString(' #'.$other.' ', $html);
        $this->assertStringContainsString('<strong class="team-mention">@bob</strong>', $html);
        $this->assertStringContainsString('@nobody &lt;b&gt;', $html);
        $this->assertSame([$this->bob->id], TeamMessage::mentioned('hi @Bob. Mail @ann@example.org', $members)->pluck('id')->all());
    }

    public function testAMentionNotifiesUntilTheRoomIsOpened()
    {
        Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->set('body', "@Bob can you take this?")->call('send');

        $notification = $this->bob->unreadNotifications()->first();
        $this->assertSame(TeamMentionNotification::class, $notification->type);
        $this->assertCount(0, $this->ann->unreadNotifications()->get());
        $entries = WebsiteNotification::fetchNotificationsData([$notification], $this->bob);
        $this->assertCount(1, $entries);
        $html = view('users/partials/web_notifications', ['web_notifications_info_data' => $entries])->render();
        $this->assertStringContainsString('Ann Lee mentioned you in Support Team Chat', $html);
        $this->assertStringContainsString(route('mailboxes.team_chat', ['id' => $this->mailbox->id]), $html);

        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox]);
        $this->assertCount(0, $this->bob->unreadNotifications()->get());
    }

    public function testRealtimeCarriesEachUsersUnreadCount()
    {
        $message = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Hello']);
        $this->actingAs($this->bob);
        $payload = RealtimeTeamMessage::processPayload((object) ['mailbox_id' => $this->mailbox->id, 'team_message_id' => $message->id]);
        $this->assertSame(1, $payload->unread);

        $this->actingAs($this->createUser());
        $this->assertSame([], RealtimeTeamMessage::processPayload((object) ['mailbox_id' => $this->mailbox->id]));
    }

    public function testFilesAreSentAndGoWithTheMailbox()
    {
        \Storage::fake('local');
        \Storage::fake(Attachment::getDiskName());

        Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->set('files', [UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->call('send')
            ->assertSee('notes.txt');
        $message = TeamMessage::where('mailbox_id', $this->mailbox->id)->first();
        $attachment = $message->attachments->first();
        $this->assertSame('notes.txt', $attachment->file_name);
        $this->assertSame(5, (int) $attachment->size);

        // Stored encrypted, sent as it was.
        $this->assertStringNotContainsString('hello', \Storage::disk(Attachment::getDiskName())->get($attachment->getStorageFilePath()));
        $this->get($attachment->url())->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('hello', $this->get($attachment->url())->getContent());

        $this->mailbox->deleteMailbox();
        $this->assertSame(0, TeamMessage::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(0, Attachment::where('team_message_id', $message->id)->count());
    }
}

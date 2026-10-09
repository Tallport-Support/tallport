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
            ->assertSee('Support Team')->assertSee('Ann, Bob')->assertSee('aria-label="Search loaded messages"', false)->assertSee('team-room', false)
            ->assertSee('data-fruit-trigger="@"', false)->assertSee('<option value="@Bob">Bob Ray</option>', false);

        $outsider = $this->createUser();
        $this->actingAs($outsider)->get(route('mailboxes.team_chat', ['id' => $this->mailbox->id]))->assertForbidden();
    }

    public function testTeamChatOpensTheRoomChosenLast()
    {
        $billing = $this->createMailbox([$this->ann, $this->bob], ['name' => 'Billing']);
        TeamMessage::create(['mailbox_id' => $billing->id, 'user_id' => $this->bob->id, 'body' => 'Invoice?']);
        $first = TeamMessage::roomFor($this->ann);

        $this->actingAs($this->ann)->get(route('team_chat'))->assertRedirect(route('mailboxes.team_chat', ['id' => $first->id]));

        // One entry in the sidebar, current in any room; the switcher lists the rooms with their unread messages.
        $this->get(route('mailboxes.team_chat', ['id' => $billing->id]))->assertOk()
            ->assertSee('app-team-chat-link" data-label-unread="Team Chat, :count unread"   aria-current="page"', false)
            ->assertSee('aria-label="Switch Team Chat"', false)
            ->assertSee('aria-label="Billing, 1 unread"', false)
            ->assertSee('aria-label="Back to Team Chat"', false)->assertSee('Files shared here appear in this list.');
        $this->assertSame($billing->id, $this->ann->fresh()->team_chat_mailbox_id);
        $this->get(route('team_chat'))->assertRedirect(route('mailboxes.team_chat', ['id' => $billing->id]));

        // One mailbox: no switcher.
        $solo = $this->createUser();
        $this->createMailbox([$solo]);
        $this->actingAs($solo)->followingRedirects()->get(route('team_chat'))->assertOk()->assertDontSee('Switch Team Chat');
    }

    public function testPinnedMessagesForEveryone()
    {
        $first = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Deploy at 5.']);
        $second = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'The VPN key is in the vault.']);

        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->call('togglePin', $second->id)->assertDispatched('team-chat-changed')
            ->assertSeeHtml('aria-label="Message from Ann Lee, pinned"')->assertSeeHtml('aria-pressed="true"');
        $this->assertNotNull($second->fresh()->pinned_at);

        // A pinned message starts its own run; the details list it for everyone.
        $html = Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])->html();
        $this->assertSame(0, substr_count($html, 'f-message--continued'));
        Livewire::actingAs($this->ann)->test(\App\Livewire\TeamChatDetails::class, ['mailbox' => $this->mailbox])
            ->assertSee('The VPN key is in the vault.')->assertDontSee('Deploy at 5.')->assertSee('You');

        Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])->call('togglePin', $second->id);
        $this->assertNull($second->fresh()->pinned_at);
        $this->assertStringContainsString('f-message--continued', Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox])->html());
    }

    public function testOlderMessagesAndPinsOpenInABoundedWindow()
    {
        $old = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'historic-exclusive', 'pinned_at' => now()]);
        $rows = [];
        for ($i = 1; $i < 620; $i++) {
            $rows[] = [
                'mailbox_id' => $this->mailbox->id,
                'user_id' => $this->ann->id,
                'body' => \Crypt::encryptString($i == 619 ? 'latest-exclusive' : 'Middle '.$i),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        \DB::table('team_messages')->insert($rows);

        $chat = Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox]);
        $chat->assertDontSee('historic-exclusive')->assertSee('latest-exclusive')->assertSee('Load older messages');
        $this->assertSame(300, substr_count($chat->html(), 'data-fruit-history-anchor='));

        $chat->call('loadOlder')->assertDontSee('latest-exclusive')->assertSee('Load newer messages');
        $this->assertSame(300, substr_count($chat->html(), 'data-fruit-history-anchor='));
        $chat->call('loadOlder')->call('loadOlder')->call('loadOlder')->assertSee('historic-exclusive')->assertDontSee('Middle 300');
        $this->assertLessThanOrEqual(300, substr_count($chat->html(), 'data-fruit-history-anchor='));
        $chat->call('loadNewer')->assertSee('Middle 300')->assertDontSee('historic-exclusive');

        $chat->call('showLatest')->assertSee('latest-exclusive')->assertDontSee('historic-exclusive');
        $chat->call('jumpTo', $old->id)->assertSee('historic-exclusive')->assertDontSee('latest-exclusive')
            ->assertDispatched('team-chat-focus', id: $old->id);
        $details = Livewire::actingAs($this->bob)->test(\App\Livewire\TeamChatDetails::class, ['mailbox' => $this->mailbox]);
        $details->assertSee('historic-exclusive')->assertSeeHtml("'team-chat-jump', { id: {$old->id} }");
    }

    public function testPinnedJumpCannotOpenAnotherMailboxMessage()
    {
        $other = $this->createMailbox([$this->ann]);
        $foreign = TeamMessage::create(['mailbox_id' => $other->id, 'user_id' => $this->ann->id, 'body' => 'private message', 'pinned_at' => now()]);

        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->call('jumpTo', $foreign->id)->assertNotFound();
        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->call('markSeenThrough', $foreign->id)->assertNotFound();
    }

    public function testNewMessagesStayUnreadUntilTheVisibleLatestWindowIsAcknowledged()
    {
        $chat = Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox]);
        $first = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => '@Bob first']);
        $second = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => '@Bob second']);
        $this->bob->notify(new TeamMentionNotification($first));
        $this->bob->notify(new TeamMentionNotification($second));

        $chat->dispatch('team-message-created')->assertDispatched('team-chat-refreshed')->assertSee('second');
        $this->assertSame([$this->mailbox->id => 2], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $chat->call('markSeenThrough', $first->id)->assertDispatched('team-chat-unread', unread: 1);
        $this->assertSame([$this->mailbox->id => 1], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $this->assertSame([$second->id], $this->bob->unreadNotifications()->get()->pluck('data.team_message_id')->all());
        $chat->call('markSeenThrough', $second->id)->assertDispatched('team-chat-unread', unread: 0)
            ->call('markSeenThrough', $first->id);
        $this->assertSame([], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $this->assertSame(0, $this->bob->unreadNotifications()->count());
    }

    public function testAnOlderWindowDoesNotAcknowledgeNewMessages()
    {
        for ($i = 0; $i < 301; $i++) {
            TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Earlier '.$i]);
        }
        $chat = Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])->call('loadOlder');
        $new = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Unseen arrival']);

        $chat->dispatch('team-message-created')->assertDontSee('Unseen arrival')->call('markSeenThrough', $new->id);
        $this->assertSame([$this->mailbox->id => 1], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $chat->call('showLatest')->assertSee('Unseen arrival')->call('markSeenThrough', $new->id);
        $this->assertSame([], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
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
            ->assertSee('aria-label="Team Chat, 2 unread"', false);

        // Opening the room: "New" from the first unread, and read.
        Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox])
            ->assertSet('first_new_id', $new->id)->assertSeeHtml('aria-label="New Messages"  class="f-divider f-divider--accent"')
            ->assertSeeHtml('aria-label="Today"')->assertSeeHtml('data-search="ann lee never mind. "');
        $this->assertSame([], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
    }

    public function testRetentionDeletesOldMessagesWithTheirFiles()
    {
        \Storage::fake(Attachment::getDiskName());
        $old = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Long ago']);
        $old->created_at = now()->subMonths(13);
        $old->save();
        $file = Attachment::create('old.txt', 'text/plain', null, \Crypt::encryptString('x'), null, false, null, $this->ann->id);
        $file->team_message_id = $old->id;
        $file->save();
        $recent = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Recent']);

        \Option::set('retention_enabled', true);
        $this->assertSame(1, \App\Retention\Retention::run(true)['team_chat']);
        $this->assertSame(1, \App\Retention\Retention::run()['team_chat']);
        $this->assertSame([$recent->id], TeamMessage::where('mailbox_id', $this->mailbox->id)->pluck('id')->all());
        $this->assertNull(Attachment::find($file->id));
        \Storage::disk(Attachment::getDiskName())->assertMissing($file->getStorageFilePath());
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
        // The unread messages of all the user's rooms.
        $billing = $this->createMailbox([$this->ann, $this->bob], ['name' => 'Billing']);
        TeamMessage::create(['mailbox_id' => $billing->id, 'user_id' => $this->ann->id, 'body' => 'Invoice?']);
        $payload = RealtimeTeamMessage::processPayload((object) ['mailbox_id' => $this->mailbox->id, 'team_message_id' => $message->id]);
        $this->assertSame(2, $payload->unread);

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

    public function testAnAttachmentFailureKeepsTheTeamChatComposerForRetry()
    {
        $chat = Livewire::actingAs($this->ann)->test(TeamChat::class, ['mailbox' => $this->mailbox]);
        $created = 0;
        $fail_second_attachment = function () use (&$created) {
            if (++$created == 2) {
                throw new \RuntimeException('Disk write failed');
            }
        };
        \Eventy::addAction('attachment.created', $fail_second_attachment);

        try {
            $chat->set('body', '@Bob Please check this.')
                ->set('files', [UploadedFile::fake()->createWithContent('a.txt', 'a'), UploadedFile::fake()->createWithContent('b.txt', 'b')])
                ->call('send')->assertToasted('Error occurred. Please try again later.', 'danger')
                ->assertSet('body', '@Bob Please check this.');
        } finally {
            \Eventy::removeAction('attachment.created', $fail_second_attachment);
        }

        $this->assertCount(2, $chat->get('files'));
        $this->assertSame(0, TeamMessage::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(0, Attachment::whereNotNull('team_message_id')->count());
        $this->assertSame([], Attachment::getDisk()->allFiles(Attachment::DIRECTORY));
        $this->assertSame(0, $this->bob->notifications()->count());
    }

    /**
     * An arrival waits for visible acknowledgement; Details follow; a file can be
     * taken off before sending, and nothing to send sends nothing.
     */
    public function testWhileTheRoomIsOpen()
    {
        $chat = Livewire::actingAs($this->bob)->test(TeamChat::class, ['mailbox' => $this->mailbox]);
        $details = Livewire::actingAs($this->bob)->test(\App\Livewire\TeamChatDetails::class, ['mailbox' => $this->mailbox]);
        $message = TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->ann->id, 'body' => 'Lunch?']);
        $this->assertSame([$this->mailbox->id => 1], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));

        $chat->dispatch('team-message-created')->assertSee('Lunch?');
        $this->assertSame([$this->mailbox->id => 1], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $chat->call('markSeenThrough', $message->id);
        $this->assertSame([], TeamMessage::unreadCounts($this->bob, [$this->mailbox->id]));
        $message->pinned_at = now();
        $message->save();
        $details->dispatch('team-chat-changed')->assertSee('Lunch?');

        $chat->set('files', [UploadedFile::fake()->createWithContent('a.txt', 'a'), UploadedFile::fake()->createWithContent('b.txt', 'b')])
            ->call('removeFile', 0);
        $this->assertSame(['b.txt'], array_map(fn ($file) => $file->getClientOriginalName(), $chat->get('files')));
        $chat->call('removeFile', 0)->set('body', '  ')->call('send');
        $this->assertSame(1, TeamMessage::where('mailbox_id', $this->mailbox->id)->count());
    }
}

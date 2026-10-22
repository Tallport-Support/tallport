<?php

namespace Tests\Feature;

use App\Conversation;
use App\ConversationRead;
use App\Folder;
use App\Inbox\Changes;
use App\Inbox\Sync;
use App\Misc\ConversationListQuery;
use App\User;
use Tests\FeatureTestCase;

/**
 * The browser's local copy of the inbox (App\Inbox): the change log, and what a sync sends.
 */
class InboxSyncTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
        Changes::forget();
    }

    protected function conversation($subject, $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => $subject,
        ]));
        Changes::forget();

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function folder($type, $user = null)
    {
        return $this->mailbox->folders()->where('type', $type)->when($user, fn ($query) => $query->where('user_id', $user->id))->first();
    }

    protected function logged()
    {
        return \DB::table('inbox_changes')->pluck('conversation_id')->all();
    }

    /**
     * Every change to what a list row shows is logged, once a request, with a hint for open tabs.
     */
    public function testChangesAreLogged()
    {
        $conversation = $this->conversation('Where is my order?');
        $this->assertContains($conversation->id, $this->logged(), 'A new conversation.');

        $checks = [
            'status'   => fn () => $conversation->changeStatus(Conversation::STATUS_PENDING, $this->agent),
            'assignee' => fn () => $conversation->changeUser($this->agent->id, $this->agent),
            'star'     => fn () => $conversation->star($this->agent),
            'read'     => fn () => ConversationRead::markRead([$conversation->id], $this->agent),
            'unread'   => fn () => ConversationRead::markUnread([$conversation->id], $this->agent),
            'reply'    => fn () => $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => 'On its way.']),
            'customer' => fn () => $conversation->customer->forceFill(['first_name' => 'Cassie'])->save(),
        ];
        foreach ($checks as $name => $change) {
            \DB::table('inbox_changes')->delete();
            Changes::forget();
            $change();
            $this->assertContains($conversation->id, $this->logged(), $name);
        }

        // Deleted forever: logged before it goes.
        \DB::table('inbox_changes')->delete();
        Changes::forget();
        Conversation::deleteConversationsForever([$conversation->id]);
        $this->assertContains($conversation->id, $this->logged());

        $this->assertTrue(\DB::table('polycast_events')->where('event', 'App\\Events\\RealtimeInboxChanged')->exists(), 'Open tabs are told.');
    }

    /**
     * A first sync: the folder in view first, then what's kept (unread, theirs and open, recent),
     * only what they may see.
     */
    public function testAFirstSync()
    {
        $unread = $this->conversation('Unread question');
        $mine = $this->conversation('Mine, read');
        $mine->changeUser($this->agent->id, $this->agent);
        ConversationRead::markRead([$mine->id], $this->agent);
        $old = $this->conversation('Old and closed');
        $old->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        ConversationRead::markRead([$old->id], $this->agent);
        Conversation::where('id', $old->id)->update(['last_activity_at' => now()->subDays(30)]);
        $elsewhere = $this->conversation('Another mailbox', $this->createMailbox([], ['name' => 'Private']));

        $sync = (new Sync($this->agent))->full(0, $this->folder(Folder::TYPE_CLOSED)->id);
        $ids = array_column($sync['rows'], 'id');
        $this->assertSame($old->id, $ids[0], 'The folder in view first.');
        $this->assertContains($unread->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids, 'Not theirs to see.');
        $this->assertFalse($sync['more']);
        $this->assertSame((new Sync($this->agent))->scope(), $sync['scope']);

        $rows = collect($sync['rows'])->keyBy('id');
        $this->assertTrue($rows[$unread->id]['unread'] && $rows[$unread->id]['keep']);
        $this->assertTrue(!$rows[$mine->id]['unread'] && $rows[$mine->id]['keep'], 'Theirs and open: kept.');
        $this->assertFalse($rows[$old->id]['keep']);
        $this->assertSame('Unread question', $rows[$unread->id]['subject']);

        // Starred: kept whatever its age.
        $old->star($this->agent);
        $starred = collect((new Sync($this->agent))->full(0)['rows'])->keyBy('id');
        $this->assertTrue($starred[$old->id]['keep'] && $starred[$old->id]['starred']);
        $old->unstar($this->agent);

        // Without the folder: only what's kept.
        $this->assertNotContains($old->id, array_column((new Sync($this->agent))->full(0)['rows'], 'id'));
    }

    /**
     * A row's folders are the folders whose lists show it, for this user.
     */
    public function testRowsAreInTheFoldersTheirListsShow()
    {
        $other = $this->createUser(['first_name' => 'Sam']);
        $this->mailbox->users()->attach($other->id);
        $this->mailbox->syncPersonalFolders([$this->agent->id, $other->id]);
        $conversations = [];
        foreach (['unassigned', 'mine', 'theirs', 'closed', 'spam', 'deleted', 'starred'] as $name) {
            $conversations[$name] = $this->conversation(ucfirst($name));
        }
        $conversations['mine']->changeUser($this->agent->id, $this->agent);
        $conversations['theirs']->changeUser($other->id, $this->agent);
        $conversations['closed']->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $conversations['spam']->changeStatus(Conversation::STATUS_SPAM, $this->agent);
        $conversations['deleted']->deleteToFolder($this->agent);
        $conversations['starred']->star($this->agent);
        $this->actingAs($this->agent);

        $rows = collect((new Sync($this->agent))->rows(collect($conversations)->pluck('id')->all()))->keyBy('id');
        foreach ($this->mailbox->folders()->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $this->agent->id))->get() as $folder) {
            $listed = collect((new ConversationListQuery())->folderList($folder, $this->agent)['conversations']->items())->pluck('id')->sort()->values()->all();
            $keyed = $rows->filter(fn ($row) => in_array($folder->id, $row['folders']))->keys()->sort()->values()->all();
            $this->assertSame($listed, $keyed, 'Folder '.$folder->type);
            $this->assertSame(count($listed), (new Sync($this->agent))->lists()[$folder->id]['total'] ?? null, 'Total of folder '.$folder->type);
        }
    }

    /**
     * With more than one mailbox, rows are in All Mailboxes' folders too (not Sent: the server's).
     */
    public function testRowsAreInAllMailboxesFolders()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversations = [$this->conversation('Unassigned'), $this->conversation('Mine', $sales), $this->conversation('Closed'), $this->conversation('Starred', $sales)];
        $conversations[1]->changeUser($this->agent->id, $this->agent);
        $conversations[2]->changeStatus(Conversation::STATUS_CLOSED, $this->agent);
        $conversations[3]->star($this->agent);
        $this->actingAs($this->agent);

        $rows = collect((new Sync($this->agent))->rows(collect($conversations)->pluck('id')->all()))->keyBy('id');
        foreach (\App\Misc\AllMailboxes::folders($this->agent) as $folder) {
            if ($folder->type == \App\Misc\AllMailboxes::TYPE_SENT) {
                continue;
            }
            $listed = collect((new ConversationListQuery())->folderList($folder, $this->agent)['conversations']->items())->pluck('id')->sort()->values()->all();
            $keyed = $rows->filter(fn ($row) => in_array($folder->id, $row['folders']))->keys()->sort()->values()->all();
            $this->assertSame($listed, $keyed, 'All Mailboxes folder '.$folder->type);
            $this->assertSame(count($listed), (new Sync($this->agent))->lists()[$folder->id]['total'] ?? null, 'Total of All Mailboxes folder '.$folder->type);
        }
        $this->assertArrayNotHasKey(-\App\Misc\AllMailboxes::TYPE_SENT, (new Sync($this->agent))->lists());
    }

    /**
     * The browser draws a folder's list in the order the server gives it (public/js/inbox.js
     * compare()): the same order as the server's list, also as the user sorted it.
     */
    public function testListsAreInTheServersOrder()
    {
        foreach (range(1, 6) as $i) {
            $conversation = $this->conversation('Question '.$i);
            if ($i % 2) {
                $conversation->changeStatus(Conversation::STATUS_PENDING, $this->agent);
            }
            \DB::table('conversations')->where('id', $conversation->id)->update([
                'last_reply_at' => now()->subHours(($i * 7) % 6),
                'last_activity_at' => now()->subHours($i),
                'updated_at' => now()->subMinutes($i * 3),
            ]);
        }
        $this->actingAs($this->agent);
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $check = function () use ($folder) {
            $spec = (new Sync($this->agent))->lists()[$folder->id];
            $rows = (new Sync($this->agent))->rows(Conversation::pluck('id')->all());
            usort($rows, function ($a, $b) use ($spec) {
                foreach ($spec['order'] as [$field, $direction]) {
                    $result = $a[$field] <=> $b[$field];
                    if ($result) {
                        return $direction == 'desc' ? -$result : $result;
                    }
                }

                return 0;
            });
            request()->attributes->set('folder_list', []);
            $listed = collect((new ConversationListQuery())->folderList($folder, $this->agent)['conversations']->items())->pluck('id')->all();
            $this->assertSame($listed, array_column($rows, 'id'));

            return $spec;
        };
        $this->assertSame([['status', 'asc'], ['last_reply', 'desc']], $check()['order']);
        $this->assertSame('last_reply', $check()['time']);

        Conversation::saveSorting($this->agent, Folder::TYPE_UNASSIGNED, ['sort_by' => 'activity', 'order' => 'asc']);
        $this->assertSame([['last_activity', 'asc']], $check()['order']);
        $this->assertSame('last_activity', $check()['time']);
    }

    /**
     * After the first sync: what changed, rows again or gone; a cursor too old, or a new scope, starts over.
     */
    public function testChangesSinceTheCursor()
    {
        $conversation = $this->conversation('Question');
        $gone = $this->conversation('Moving away');
        $cursor = Changes::latest();

        $conversation->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $private = $this->createMailbox([], ['name' => 'Private']);
        $gone->moveToMailbox($private, $this->agent);
        $sync = (new Sync($this->agent))->since($cursor);
        $this->assertSame([$conversation->id], array_column($sync['rows'], 'id'));
        $this->assertSame(Conversation::STATUS_PENDING, $sync['rows'][0]['status']);
        $this->assertSame([$gone->id], $sync['removed']);
        $this->assertSame([], (new Sync($this->agent))->since($sync['cursor'])['rows'], 'Nothing new.');

        // Its scope changes with what they may see.
        $scope = (new Sync($this->agent))->scope();
        $private->users()->attach($this->agent->id);
        $this->assertNotSame($scope, (new Sync(User::find($this->agent->id)))->scope());

        // The change log keeps 30 days; an older cursor starts over.
        \DB::table('inbox_changes')->update(['created_at' => now()->subDays(31)]);
        $this->conversation('New');
        Changes::prune();
        $this->assertTrue((new Sync($this->agent))->since($cursor)['restart']);
    }

    /**
     * What a conversation's pane shows, in short (a snapshot in the browser is of one version):
     * it changes with a message, a translation or its status; the page and an open in place say it.
     */
    public function testThePanesVersion()
    {
        $conversation = $this->conversation('Where is my order?');
        $version = fn () => Sync::paneVersions([$conversation->fresh()])[$conversation->id];
        $first = $version();
        $this->assertSame($first, collect((new Sync($this->agent))->rows([$conversation->id]))->first()['pane']);
        $this->assertSame($first, $version(), 'Unchanged.');

        $this->travel(1)->seconds();
        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => 'On its way.']);
        $this->assertNotSame($first, $second = $version(), 'A message.');

        $this->travel(1)->seconds();
        \DB::table('inbox_changes')->delete();
        Changes::forget();
        \App\Ai\Translations::store($conversation->threads()->where('type', \App\Thread::TYPE_CUSTOMER)->first(), 'nl', 'en', 'Waar is mijn bestelling?');
        $this->assertNotSame($second, $third = $version(), 'A translation.');
        $this->assertContains($conversation->id, $this->logged(), 'Synced again.');

        $this->travel(1)->seconds();
        $conversation->fresh()->changeStatus(Conversation::STATUS_PENDING, $this->agent);
        $this->assertNotSame($third, $version(), 'Its status.');

        $this->actingAs($this->agent)->withUnencryptedCookie('tallport_narrow', '0')->get('/conversation/'.$conversation->id)
            ->assertSee('data-pane-version="'.$version().'"', false);
        \Livewire\Livewire::actingAs($this->agent)->test(\App\Livewire\ConversationPane::class, ['conversation' => $conversation->fresh(), 'folder' => $conversation->folder])
            ->dispatch('conversation-open', id: $conversation->id)->assertDispatched('conversation-opened', version: $version());
    }

    /**
     * A conversation fetched ahead: rendered as it would show, but without what opening it
     * does: nothing asked of the AI (a summary, a translation), not read, nobody told.
     */
    public function testAConversationFetchedAheadHasNoSideEffects()
    {
        \App\Option::set('aiassistant.api_key', encrypt('sk-test'));
        \App\Option::set('aiassistant.summary_conversation_threshold', 0);
        \App\Option::set('aiassistant.translation_language', 'en');
        \App\Option::set('aiassistant.mailbox_email_translation', [$this->mailbox->id => 1]);
        \App\Option::$cache = [];
        $conversation = $this->conversation('Waar is mijn bestelling?');
        \App\Ai\Translations::store($conversation->threads()->where('type', \App\Thread::TYPE_CUSTOMER)->first(), 'de', 'nl', 'Wo ist meine Bestellung?');
        \DB::table('polycast_events')->delete();
        $this->withUnencryptedCookie('tallport_narrow', '0');

        // Opened, it asks the AI for its summary (this test can tell).
        \Queue::fake();
        $this->actingAs($this->agent)->get('/conversation/'.$conversation->id)->assertOk();
        \Queue::assertPushed(\App\Jobs\AiSummarizeConversation::class);

        \Queue::fake();
        ConversationRead::markUnread([$conversation->id], $this->agent);
        \DB::table('polycast_events')->delete();
        $writes = [];
        \DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->actingAs($this->agent)->getJson(route('inbox.pane', ['id' => $conversation->id]))->assertOk()
            ->assertJsonPath('version', Sync::paneVersions([$conversation->fresh()])[$conversation->id]);
        $this->assertStringContainsString('Waar is mijn bestelling?', $response->json('parts')['.conv-pane']);
        $this->assertStringContainsString('conv-toolbar', $response->json('parts')['#conv-toolbar']);

        \Queue::assertNothingPushed();
        $this->assertSame([], $writes, 'Nothing written.');
        $this->assertSame(0, \DB::table('polycast_events')->count(), 'Nobody told.');
        $this->assertSame([$conversation->id], ConversationRead::unreadIds([$conversation], $this->agent), 'Still unread.');

        $this->actingAs($this->createUser())->getJson(route('inbox.pane', ['id' => $conversation->id]))->assertNotFound();
    }

    public function testTheEndpointAndTheSessionsKey()
    {
        $conversation = $this->conversation('Question');
        $this->getJson(route('inbox.sync', ['page' => 0]))->assertUnauthorized();

        $this->actingAs($this->agent)->getJson(route('inbox.sync', ['page' => 0]))->assertOk()->assertJsonPath('rows.0.id', $conversation->id)
;
        $this->assertStringContainsString('no-store', $this->actingAs($this->agent)->getJson(route('inbox.sync', ['page' => 0]))->headers->get('Cache-Control'));
        $this->actingAs($this->agent)->getJson(route('inbox.sync', ['cursor' => Changes::latest()]))->assertOk()->assertJsonStructure(['cursor', 'scope', 'rows', 'removed', 'more']);

        // One key a session, on the page for the browser.
        $page = $this->actingAs($this->agent)->get('/')->getContent();
        $this->assertStringContainsString('data-inbox=', $page);
        // Times as the list writes them (User::dateDiffForHumans()).
        $config = Sync::config($this->agent);
        $this->assertSame('5m', $config['times']['m'][5]);
        $this->assertSame('1w', $config['times']['d'][7]);
        $this->assertSame(Sync::sessionKey(), Sync::sessionKey());
        $this->assertSame(32, strlen(base64_decode(Sync::sessionKey())));
    }
}

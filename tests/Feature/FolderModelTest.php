<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * The Folder model: its order of conversations, counters, how long the
 * oldest active conversation has waited, and creating folders.
 */
class FolderModelTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function folder($type, $user = null)
    {
        $query = $this->mailbox->folders()->where('type', $type);
        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->first();
    }

    /**
     * A conversation in the mailbox, last replied to at the given time.
     */
    protected function conversation($subject, $last_reply_at)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => $subject,
        ]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $conversation->last_reply_at = $last_reply_at;
        $conversation->save();

        return $conversation;
    }

    public function testPersonalFolderBelongsToItsUser()
    {
        $this->assertSame($this->agent->id, $this->folder(Folder::TYPE_MINE, $this->agent)->user->id);
        $this->assertNull($this->folder(Folder::TYPE_UNASSIGNED)->user);
    }

    public function testDraftsAreOrderedByLastChange()
    {
        $this->assertSame([['updated_at' => 'desc']], $this->folder(Folder::TYPE_DRAFTS)->getOrderByArray());
    }

    public function testOldestFirstReversesTheDateOrderAndDropsTheStatusGrouping()
    {
        $this->app['request']->merge(['sorting' => ['sort_by' => 'date', 'order' => 'asc']]);

        $this->assertSame([[], ['last_reply_at' => 'asc']], $this->folder(Folder::TYPE_UNASSIGNED)->getOrderByArray());
        $this->assertSame([['closed_at' => 'asc']], $this->folder(Folder::TYPE_CLOSED)->getOrderByArray());

        $older = $this->conversation('Older', '2026-01-01 10:00:00');
        $newer = $this->conversation('Newer', '2026-02-01 10:00:00');
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $ids = $folder->queryAddOrderBy($folder->conversations())->pluck('id')->all();
        $this->assertSame([$older->id, $newer->id], $ids);
    }

    public function testModulesCanTakeOverCounting()
    {
        $folder = $this->folder(Folder::TYPE_UNASSIGNED);
        $this->conversation('Waiting', '2026-01-01 10:00:00');
        $folder->active_count = 5;
        $folder->save();

        \Eventy::addFilter('folder.update_counters', function () {
            return true;
        });
        $folder->updateCountersNow();
        $this->assertSame(5, (int) $folder->fresh()->active_count, 'Left to the module.');

        \Eventy::addFilter('folder.count', function ($count, $counted_folder) use ($folder) {
            return $counted_folder->id == $folder->id ? 42 : $count;
        }, 20, 2);
        $this->assertSame(42, $folder->getCount());
    }

    public function testAssignedCountLeavesOutTheUsersOwnAndIsNeverNegative()
    {
        $assigned = $this->folder(Folder::TYPE_ASSIGNED);
        $mine = $this->folder(Folder::TYPE_MINE, $this->agent);
        $assigned->active_count = 5;
        $mine->active_count = 2;
        $this->assertSame(3, $assigned->getActiveCount(collect([$mine])));

        $mine->active_count = 7;
        $this->assertSame(0, $assigned->getActiveCount(collect([$mine])));
    }

    public function testWaitingSince()
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $unassigned = $this->folder(Folder::TYPE_UNASSIGNED);
        $mine = $this->folder(Folder::TYPE_MINE, $this->agent);
        $starred = $this->folder(Folder::TYPE_STARRED, $this->agent);
        $this->assertSame('', $unassigned->getWaitingSince(), 'Nothing waiting.');

        $this->conversation('Oldest', '2026-06-15 09:00:00');
        $this->conversation('Newer', '2026-06-15 11:00:00');
        $this->assertSame('3 hours ago', $unassigned->getWaitingSince(), 'The oldest active conversation.');

        $assigned = $this->conversation('Assigned', '2026-06-15 07:00:00');
        $assigned->user_id = $this->agent->id;
        $assigned->save();
        $this->assertSame('5 hours ago', $mine->getWaitingSince(), 'Mine: the user\'s conversations.');

        $assigned->star($this->agent);
        $this->assertSame([$assigned->id], $starred->getWaitingSinceQuery()->pluck('conversations.id')->all(), 'Starred: through conversation_folder.');
    }

    public function testWaitingSinceField()
    {
        $this->assertSame('updated_at', $this->folder(Folder::TYPE_DRAFTS)->getWaitingSinceField());
        $this->assertSame('closed_at', $this->folder(Folder::TYPE_CLOSED)->getWaitingSinceField());
        $this->assertSame('user_updated_at', $this->folder(Folder::TYPE_DELETED)->getWaitingSinceField());
        $this->assertSame('last_reply_at', $this->folder(Folder::TYPE_ASSIGNED)->getWaitingSinceField());
    }

    public function testCreate()
    {
        $this->assertNull(Folder::create(['type' => Folder::TYPE_MINE]));
        $this->assertNull(Folder::create(['mailbox_id' => $this->mailbox->id]));

        $mine = $this->folder(Folder::TYPE_MINE, $this->agent);
        $data = ['mailbox_id' => $this->mailbox->id, 'user_id' => $this->agent->id, 'type' => Folder::TYPE_MINE];
        $this->assertSame($mine->id, Folder::create($data)->id, 'A user has one folder of a type.');

        $unsaved = Folder::create($data, false, false);
        $this->assertFalse($unsaved->exists);
        $this->assertSame($this->agent->id, $unsaved->user_id);

        $public = Folder::create(['mailbox_id' => $this->mailbox->id, 'type' => Folder::TYPE_SPAM]);
        $this->assertTrue($public->exists);
        $this->assertNull($public->user_id);
    }
}

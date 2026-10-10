<?php

namespace Tests\Feature;

use App\Conversation;
use App\Incoming\RawSources;
use App\Retention\Retention;
use App\Thread;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * The raw source of each incoming email, kept compressed in the database for
 * as long as its thread (App\Incoming\RawSources), and the import of the
 * files kept before (storage/app/incoming-mail).
 */
class ThreadSourcesTest extends FeatureTestCase
{
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = $this->createMailbox([$this->createUser()], ['email' => 'support@sources.example']);
    }

    protected function receive($message_id, array $options = [])
    {
        $raw = preg_replace("/\r?\n/", "\r\n", $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example>', 'to' => 'support@sources.example', 'message_id' => $message_id,
        ], $options)));
        $this->receiveEmail($this->mailbox, $raw);

        return [Thread::where('message_id', $message_id)->firstOrFail(), $raw];
    }

    /**
     * The stored bytes, as the database returns them.
     */
    protected function storedBytes($thread_id)
    {
        $source = DB::table(RawSources::TABLE)->where('thread_id', $thread_id)->value('source');

        return is_resource($source) ? stream_get_contents($source) : $source;
    }

    public function testFetchedEmailIsStoredCompressed()
    {
        [$thread, $raw] = $this->receive('fetched@customer.example', ['body' => str_repeat("A line of the customer's question.\n", 200)]);

        $this->assertSame($raw, RawSources::get($thread));
        $stored = $this->storedBytes($thread->id);
        $this->assertStringStartsWith("\x1f\x8b", $stored, 'gzip');
        $this->assertLessThan(strlen($raw) / 4, strlen($stored));
    }

    /**
     * Any bytes come back exactly: 8-bit text, NUL bytes, a large email.
     */
    public function testSourcesComeBackByteForByte()
    {
        [$thread] = $this->receive('bytes@customer.example');
        foreach (["Subject: Caf\xC3\xA9\r\n\r\n\x00\x01\xFF\xFE binary \x00", random_bytes(3 * 1024 * 1024), ''] as $raw) {
            RawSources::put($thread->id, $raw);

            $this->assertSame($raw, RawSources::get($thread));
            $this->assertSame(1, DB::table(RawSources::TABLE)->where('thread_id', $thread->id)->count());
        }

        $missing = new Thread();
        $missing->id = 999999;
        $this->assertNull(RawSources::get($missing));
    }

    public function testSourcesAreDeletedWithTheirThreads()
    {
        // Deleted for good (also the Deleted folder's Empty, the API, mailbox deletion).
        [$forever] = $this->receive('forever@customer.example');
        $forever->conversation->deleteForever();
        $this->assertSame(0, DB::table(RawSources::TABLE)->where('thread_id', $forever->id)->count());

        // Retention's trash period.
        [$trashed] = $this->receive('trash@customer.example');
        DB::table('conversations')->where('id', $trashed->conversation_id)
            ->update(['state' => Conversation::STATE_DELETED, 'user_updated_at' => now()->subYears(5)]);
        $this->assertGreaterThan(0, Retention::deleteTrash());
        $this->assertSame(0, DB::table(RawSources::TABLE)->where('thread_id', $trashed->id)->count());

        // A conversation model deleted.
        [$model] = $this->receive('model@customer.example');
        $model->conversation->delete();
        $this->assertSame(0, DB::table(RawSources::TABLE)->where('thread_id', $model->id)->count());

        // A single thread.
        [$single, $raw] = $this->receive('single@customer.example');
        [$other] = $this->receive('other@customer.example');
        $single->deleteThread();
        $this->assertSame(0, DB::table(RawSources::TABLE)->where('thread_id', $single->id)->count());
        $this->assertNotNull(RawSources::get($other));
    }

    public function testFilesKeptBeforeAreImported()
    {
        if (!class_exists('CreateThreadSourcesTable')) {
            require base_path('database/migrations/2026_11_14_010101_create_thread_sources_table.php');
        }
        [$thread] = $this->receive('imported@customer.example');
        [$stored] = $this->receive('stored@customer.example');
        DB::table(RawSources::TABLE)->where('thread_id', $thread->id)->delete();
        $storage = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        $folder = $storage.'/app/incoming-mail';
        mkdir($folder, 0777, true);
        $this->app->useStoragePath($storage);
        try {
            $raw = "From: casey@customer.example\r\nSubject: Kept\r\n\r\nCaf\xC3\xA9 \x00";
            file_put_contents($folder.'/'.$thread->id.'.eml', $raw);
            // Already in the table: kept as it is.
            file_put_contents($folder.'/'.$stored->id.'.eml', 'Older copy');
            // Threads deleted since: more than a batch of them.
            for ($id = 900001; $id <= 900150; $id++) {
                file_put_contents($folder.'/'.$id.'.eml', 'Orphaned');
            }
            file_put_contents($folder.'/notes.txt', 'Something else');

            (new \CreateThreadSourcesTable())->up();

            $this->assertSame($raw, RawSources::get($thread));
            $this->assertNotSame('Older copy', RawSources::get($stored));
            $this->assertSame(0, DB::table(RawSources::TABLE)->where('thread_id', '>', 900000)->count());
            $this->assertDirectoryDoesNotExist($folder);

            // Again: nothing to do.
            (new \CreateThreadSourcesTable())->up();
            $this->assertSame($raw, RawSources::get($thread));
        } finally {
            (new Filesystem())->deleteDirectory($storage);
        }
    }
}

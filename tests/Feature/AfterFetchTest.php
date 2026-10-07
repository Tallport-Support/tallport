<?php

namespace Tests\Feature;

use App\Console\Commands\FetchEmails;
use App\Incoming\AfterFetch;
use App\Incoming\FetchedMessage;
use App\Mailbox;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\FeatureTestCase;

/**
 * After fetching an email, the mail server can keep it (read), or it is
 * removed or moved to a folder.
 */
class AfterFetchTest extends FeatureTestCase
{
    /**
     * IMAP commands the fake server got.
     */
    protected $commands = [];

    /**
     * Commands the fake server refuses, by their start (e.g. MOVE).
     */
    protected $unsupported = [];

    protected $mailbox;

    /**
     * Whether the fake server has the folder to move to.
     */
    protected $folder_exists = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = $this->createMailbox([], ['in_protocol' => Mailbox::IN_PROTOCOL_IMAP]);
    }

    /**
     * An email fetched from INBOX (UID 7) of a fake server.
     */
    protected function fetched()
    {
        $test = $this;
        $response = function ($command) use ($test) {
            $test->commands[] = $command;

            $fails = (bool) array_filter($test->unsupported, fn ($start) => str_starts_with($command, $start));

            return new class($fails) {
                public function __construct(public $fails)
                {
                }

                public function validatedData()
                {
                    if ($this->fails) {
                        throw new \RuntimeException('BAD command unknown');
                    }

                    return true;
                }
            };
        };
        $connection = new class($response) {
            public function __construct(public $response)
            {
            }

            public function store($flags, $from, $to = null, $mode = null, $silent = true, $uid = 1)
            {
                return ($this->response)('STORE '.$from.' '.$mode.'FLAGS '.implode(' ', $flags));
            }

            public function moveMessage($folder, $from, $to = null, $uid = 1)
            {
                return ($this->response)('MOVE '.$from.' '.$folder);
            }

            public function copyMessage($folder, $from, $to = null, $uid = 1)
            {
                return ($this->response)('COPY '.$from.' '.$folder);
            }

            public function requestAndResponse($command, $tokens = [])
            {
                return ($this->response)(str_replace('UID ', '', $command).' '.implode(' ', $tokens));
            }

            public function expunge()
            {
                return ($this->response)('EXPUNGE');
            }
        };
        $client = new class($connection, $test) {
            public function __construct(public $connection, public $test)
            {
            }

            public function openFolder($path)
            {
            }

            public function getConnection()
            {
                return $this->connection;
            }

            public function getFolderByPath($path)
            {
                return $this->test->folderExists() ? (object) ['path' => $path] : null;
            }
        };

        return new FetchedMessage("Message-ID: <a@example.org>\r\nSubject: Hi\r\n\r\nHello", $client, 'INBOX', 7);
    }

    public function folderExists()
    {
        return $this->folder_exists;
    }

    protected function setAction($action, $folder = '')
    {
        AfterFetch::save($this->mailbox, $action, $folder);
        $this->mailbox->save();
    }

    public function testMarkedAsReadOnly()
    {
        $this->assertSame(['action' => AfterFetch::LEAVE, 'folder' => ''], AfterFetch::settings($this->mailbox));
        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame([], $this->commands);
    }

    public function testRemoved()
    {
        $this->setAction(AfterFetch::REMOVE);

        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame(['STORE 7 +FLAGS \\Deleted', 'EXPUNGE 7'], $this->commands, 'Only this email (UIDPLUS).');

        // Without UIDPLUS.
        $this->commands = [];
        $this->unsupported = ['EXPUNGE 7'];
        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame(['STORE 7 +FLAGS \\Deleted', 'EXPUNGE 7', 'EXPUNGE'], $this->commands);
    }

    public function testMoved()
    {
        $this->setAction(AfterFetch::MOVE, 'Archive');

        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame(['MOVE 7 Archive'], $this->commands);
        $this->assertSame(['INBOX', 'Archive'], AfterFetch::searchFolders($this->mailbox), 'Where its original can be found.');

        // Without MOVE: copied, then removed.
        $this->commands = [];
        $this->unsupported = ['MOVE'];
        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame(['MOVE 7 Archive', 'COPY 7 Archive', 'STORE 7 +FLAGS \\Deleted', 'EXPUNGE 7'], $this->commands);

        // Not over POP3.
        $this->commands = [];
        $this->mailbox->in_protocol = Mailbox::IN_PROTOCOL_POP3;
        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame([], $this->commands);
    }

    /**
     * tallport:fetch-emails does it after marking the email as read.
     */
    public function testFetchingRemovesAndReportsErrors()
    {
        $this->setAction(AfterFetch::REMOVE);
        $this->unsupported = ['STORE 7 +FLAGS \\Deleted'];
        $command = new class() extends FetchEmails {
            public $buffer;

            public function __construct()
            {
                parent::__construct();
                $this->buffer = new BufferedOutput();
                $this->output = new OutputStyle(new ArrayInput([]), $this->buffer);
            }
        };
        $command->mailbox = $this->mailbox;

        $command->setSeen($this->fetched(), $this->mailbox);

        $this->assertSame('STORE 7 +FLAGS \\Seen', $this->commands[0]);
        $this->assertStringContainsString('After fetching (remove): BAD command unknown', $command->buffer->fetch());
    }

    public function testSettings()
    {
        $admin = $this->createAdmin();
        $save = function ($data) use ($admin) {
            \Session::start();

            return $this->actingAs($admin)->post(route('mailboxes.connection.incoming.save', ['id' => $this->mailbox->id]), array_merge([
                '_token' => csrf_token(), 'in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => '1.1.1.1',
                'in_port' => 993, 'in_username' => 'support', 'in_password' => 'secret', 'in_encryption' => Mailbox::IN_ENCRYPTION_SSL,
            ], $data));
        };

        $save(['after_fetch_action' => AfterFetch::MOVE, 'after_fetch_folder' => 'INBOX'])->assertSessionHasErrors('after_fetch_folder');
        $save(['after_fetch_action' => AfterFetch::MOVE, 'after_fetch_folder' => ''])->assertSessionHasErrors('after_fetch_folder');
        $save(['after_fetch_action' => AfterFetch::MOVE, 'after_fetch_folder' => 'Archive'])->assertSessionHasNoErrors();
        $this->assertSame(['action' => AfterFetch::MOVE, 'folder' => 'Archive'], AfterFetch::settings($this->mailbox->fresh()));

        $this->get(route('mailboxes.connection.incoming', ['id' => $this->mailbox->id]))->assertOk()
            ->assertSee('After Fetching')->assertSee('value="Archive"', false);
    }

    public function testEarlierSettingIsKept()
    {
        require_once base_path('database/migrations/2026_10_15_010101_after_fetch.php');
        $this->mailbox->setMeta('imapmove', ['action' => 3, 'folder' => 'Done']);
        $this->mailbox->save();

        \AfterFetch::importSettings($this->mailbox);

        $this->assertSame(['action' => AfterFetch::MOVE, 'folder' => 'Done'], AfterFetch::settings($this->mailbox->fresh()));
    }

    /**
     * Moved to the folder it was fetched from: nothing to do. A folder that isn't there: said so.
     */
    public function testMovedWhereItIsOrNowhere()
    {
        $this->setAction(AfterFetch::MOVE, 'INBOX');
        $this->assertNull(AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame([], $this->commands);

        $this->setAction(AfterFetch::MOVE, 'Archive');
        $this->folder_exists = false;
        $this->assertSame('IMAP folder not found on the mail server: Archive', AfterFetch::apply($this->fetched(), $this->mailbox));
        $this->assertSame([], $this->commands);
    }

    /**
     * A raw message's date: its Date header, or now when that isn't a date.
     */
    public function testRawMessageDate()
    {
        $this->assertSame('2024-03-01 10:00:00', (new FetchedMessage("Date: Fri, 01 Mar 2024 10:00:00 +0000\r\n\r\nHi"))->getDate()->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertTrue((new FetchedMessage("Date: not a date\r\n\r\nHi"))->getDate()->isToday());
    }
}

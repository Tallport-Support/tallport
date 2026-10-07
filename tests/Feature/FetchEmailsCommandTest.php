<?php

namespace Tests\Feature;

use App\Conversation;
use App\Console\Commands\FetchEmails;
use App\Mailbox;
use App\Option;
use App\Thread;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\FeatureTestCase;

/**
 * tallport:fetch-emails against a fake IMAP server: which mailboxes and
 * folders are fetched, what is saved and marked as read on the server.
 */
class FetchEmailsCommandTest extends FeatureTestCase
{
    /**
     * A minimal IMAP server (webklex/php-imap 6 speaks to it). Its first
     * argument is a JSON file: folders (name => uid => [raw, flags]), log
     * (a file every command line is appended to) and refuse (start of
     * "selected folder:command" => NO response text). Prints its port, serves connections one after
     * another until none comes for a few seconds.
     */
    const SERVER = <<<'PHP'
$config = json_decode(file_get_contents($argv[1]), true);
$folders = $config['folders'];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);
$log = function ($line) use ($config) {
    file_put_contents($config['log'], $line."\n", FILE_APPEND);
};
while ($client = @stream_socket_accept($server, 3)) {
    fwrite($client, "* OK [CAPABILITY IMAP4rev1] Fake IMAP ready\r\n");
    $selected = null;
    while (($line = fgets($client)) !== false) {
        $line = trim($line);
        $log($line);
        $words = explode(' ', $line);
        $tag = $words[0];
        $command = strtoupper($words[1] ?? '');
        $args = array_slice($words, 2);
        if ($command == 'UID') {
            $command = 'UID '.strtoupper(array_shift($args));
        }
        $refused = null;
        foreach ($config['refuse'] ?? [] as $start => $text) {
            if (str_starts_with($selected.':'.$command.' '.implode(' ', $args), $start)) {
                $refused = $text;
            }
        }
        if ($refused !== null) {
            fwrite($client, "$tag NO $refused\r\n");
            continue;
        }
        $messages = $selected !== null ? ($folders[$selected] ?? []) : [];
        switch ($command) {
            case 'LIST':
                $response = '';
                foreach (array_keys($folders) as $name) {
                    $response .= "* LIST (\\HasNoChildren) \"/\" \"$name\"\r\n";
                }
                $response .= "$tag OK LIST completed\r\n";
                break;
            case 'SELECT':
            case 'EXAMINE':
                $selected = trim(implode(' ', $args), '"');
                $response = '* '.count($folders[$selected] ?? [])." EXISTS\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n$tag OK [READ-WRITE] $command completed\r\n";
                break;
            case 'UID SEARCH':
                $uids = [];
                foreach ($messages as $uid => $message) {
                    if (stripos($line, 'UNSEEN') === false || !in_array('\\Seen', $message['flags'])) {
                        $uids[] = $uid;
                    }
                }
                $response = '* SEARCH '.implode(' ', $uids)."\r\n$tag OK SEARCH completed\r\n";
                break;
            case 'UID FETCH':
                preg_match('/^UID FETCH ([\d,:]+) \(([^)]+)\)/i', substr($line, strlen($tag) + 1), $m);
                $set = [];
                foreach (explode(',', $m[1]) as $range) {
                    [$from, $to] = array_pad(explode(':', $range), 2, null);
                    $set = array_merge($set, range($from, $to ?? $from));
                }
                $item = strtoupper($m[2]);
                $response = '';
                foreach ($set as $uid) {
                    if (!isset($messages[$uid])) {
                        continue;
                    }
                    $raw = $messages[$uid]['raw'];
                    [$header, $body] = explode("\r\n\r\n", $raw, 2);
                    if ($item == 'FLAGS') {
                        $response .= "* $uid FETCH (UID $uid FLAGS (".implode(' ', $messages[$uid]['flags'])."))\r\n";
                    } else {
                        $data = $item == 'RFC822.HEADER' ? $header."\r\n\r\n" : ($item == 'RFC822.TEXT' ? $body : $raw);
                        $response .= "* $uid FETCH (UID $uid $item {".strlen($data)."}\r\n".$data.")\r\n";
                    }
                }
                $response .= "$tag OK FETCH completed\r\n";
                break;
            case 'UID STORE':
                $uid = (int) $args[0];
                $flags = array_diff($folders[$selected][$uid]['flags'], ['\\Seen']);
                if ($args[1][0] == '+') {
                    $flags[] = '\\Seen';
                }
                $folders[$selected][$uid]['flags'] = array_values($flags);
                $response = "$tag OK STORE completed\r\n";
                break;
            case 'LOGOUT':
                fwrite($client, "* BYE Logging out\r\n$tag OK LOGOUT completed\r\n");
                break 2;
            default:
                $response = "$tag OK $command completed\r\n";
        }
        fwrite($client, $response);
    }
    fclose($client);
}
PHP;

    protected $server;

    protected $files = [];

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Start the fake IMAP server.
     *
     * @return int The port.
     */
    protected function startServer(array $folders, array $refuse = [])
    {
        $config = tempnam(sys_get_temp_dir(), 'imap-config');
        $log = tempnam(sys_get_temp_dir(), 'imap-log');
        $this->files = [$config, $log];
        file_put_contents($config, json_encode(['folders' => $folders, 'log' => $log, 'refuse' => (object) $refuse]));

        $this->server = proc_open([PHP_BINARY, '-r', self::SERVER, $config], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        return (int) fgets($pipes[1]);
    }

    /**
     * Command lines the fake server got.
     */
    protected function serverLog()
    {
        return array_filter(explode("\n", file_get_contents($this->files[1])));
    }

    protected function imapMailbox($port, array $attributes = [])
    {
        $mailbox = $this->createMailbox([$this->createUser()], array_merge(['name' => 'Fetched'], $attributes));
        $mailbox->fill(['in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => '127.0.0.1', 'in_port' => $port, 'in_username' => 'u', 'in_password' => 'p', 'in_encryption' => Mailbox::IN_ENCRYPTION_NONE])->save();

        return $mailbox;
    }

    /**
     * Run tallport:fetch-emails (the real command, not the stub).
     *
     * @return string The output.
     */
    protected function fetch(array $options = [])
    {
        $command = new FetchEmails();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $command->run(new ArrayInput($options), $output);
        gc_collect_cycles();

        return $output->fetch();
    }

    protected function message($uid, $subject, array $options = [])
    {
        return array_merge(['raw' => $this->makeEmail(array_merge([
            'from'       => 'Casey Customer <casey@customer.example.org>',
            'to'         => 'fetched@example.org',
            'subject'    => $subject,
            'message_id' => 'fetched-'.$uid.'@customer.example.org',
        ], $options)), 'flags' => []], $options['imap'] ?? []);
    }

    public function testFetchesUnreadEmailsOldestFirstAndMarksThemRead()
    {
        $port = $this->startServer(['INBOX' => [
            3 => $this->message(3, 'Later email', ['date' => date('r', strtotime('-1 hour'))]),
            5 => $this->message(5, 'Earlier email', ['date' => date('r', strtotime('-2 hours'))]),
            7 => $this->message(7, 'Already read', ['imap' => ['flags' => ['\\Seen']]]),
        ]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $output = $this->fetch();

        $this->assertStringContainsString('Fetching UNREAD emails for the last 3 days.', $output);
        $this->assertStringContainsString('Mailbox: Fetched', $output);
        $this->assertStringContainsString('Folder: INBOX', $output);
        $this->assertStringContainsString('Fetched: 2', $output);
        $this->assertMatchesRegularExpression('/1\) Earlier email.*2\) Later email/s', $output);
        $this->assertStringContainsString('Fetching finished', $output);

        $this->assertSame(['Earlier email', 'Later email'], Conversation::where('mailbox_id', $mailbox->id)->orderBy('id')->pluck('subject')->all());
        $this->assertSame('casey@customer.example.org', Conversation::where('mailbox_id', $mailbox->id)->value('customer_email'));

        $log = $this->serverLog();
        $this->assertTrue((bool) preg_grep('/UID SEARCH SINCE "\d\d-\w{3}-\d{4}" UNSEEN$/', $log));
        foreach ([3, 5] as $uid) {
            $this->assertTrue((bool) preg_grep('/UID STORE '.$uid.':'.$uid.' \+FLAGS\.SILENT \(\\\\Seen\)/', $log), "Email $uid is marked as read.");
        }
        $this->assertFalse((bool) preg_grep('/UID STORE 7:7/', $log));

        $this->assertNotEmpty(Option::get('fetch_emails_last_run'));
        $this->assertNotEmpty(Option::get('fetch_emails_last_successful_run'));
    }

    /**
     * With --unseen=0 read emails are fetched too; an email fetched before is
     * not saved again.
     */
    public function testFetchingAllSkipsEmailsFetchedBefore()
    {
        // Built once: the same email (and Date header) on the server and received before.
        $read_before = $this->message(3, 'Read before', ['imap' => ['flags' => ['\\Seen']]]);
        $port = $this->startServer(['INBOX' => [
            3 => $read_before,
            4 => $this->message(4, 'New one'),
        ]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        $this->receiveEmail($mailbox, $read_before['raw']);

        $output = $this->fetch(['--unseen' => '0', '--days' => '10']);

        $this->assertStringContainsString('Fetching ALL emails for the last 10 days.', $output);
        $this->assertStringContainsString('Message with such Message-ID has been fetched before: fetched-3@customer.example.org', $output);
        $this->assertSame(['Read before', 'New one'], Conversation::where('mailbox_id', $mailbox->id)->orderBy('id')->pluck('subject')->all());
        $this->assertFalse((bool) preg_grep('/UNSEEN/', $this->serverLog()));
    }

    /**
     * A module can change per mailbox whether only unread emails are fetched.
     */
    public function testUnseenFilter()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'Read on the server', ['imap' => ['flags' => ['\\Seen']]])]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        \Eventy::addFilter('fetch_emails.unseen', function ($unseen, $fetched_mailbox) use ($mailbox) {
            return $fetched_mailbox->id == $mailbox->id ? 0 : $unseen;
        }, 20, 2);

        $output = $this->fetch();

        $this->assertStringContainsString('Fetching: ALL', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $mailbox->id)->count());
    }

    public function testOnlyTheGivenMailboxes()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'For whoever fetches')]]);
        $skipped = $this->imapMailbox($port, ['name' => 'Skipped', 'email' => 'skipped@example.org']);
        $fetched = $this->imapMailbox($port, ['name' => 'Chosen', 'email' => 'fetched@example.org']);

        $output = $this->fetch(['--mailboxes' => '0,'.$fetched->id]);

        $this->assertStringContainsString('Mailbox: Chosen', $output);
        $this->assertStringNotContainsString('Mailbox: Skipped', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $fetched->id)->count());
        $this->assertSame(0, Conversation::where('mailbox_id', $skipped->id)->count());
        $this->assertCount(1, preg_grep('/LOGIN/', $this->serverLog()), 'One connection.');
    }

    /**
     * The mailbox's IMAP folders are fetched; a missing one is reported. A
     * module can save a folder's emails into another mailbox.
     */
    public function testFolders()
    {
        $port = $this->startServer([
            'INBOX'   => [3 => $this->message(3, 'In the inbox')],
            'Archive' => [4 => $this->message(4, 'In the archive')],
        ]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        $mailbox->in_imap_folders = json_encode(['INBOX', 'Archive', 'Missing']);
        $mailbox->save();
        $other = $this->createMailbox([], ['name' => 'Archive Mailbox']);
        \Eventy::addFilter('fetch_emails.mailbox_to_save_message', function ($save_to, $folder) use ($other) {
            return $folder->name == 'Archive' ? $other : $save_to;
        }, 20, 2);

        $output = $this->fetch();

        $this->assertStringContainsString('Folder: Archive', $output);
        $this->assertStringContainsString('IMAP folder not found on the mail server: Missing', $output);
        $this->assertSame(['In the inbox'], Conversation::where('mailbox_id', $mailbox->id)->pluck('subject')->all());
        $this->assertSame(['In the archive'], Conversation::where('mailbox_id', $other->id)->pluck('subject')->all());
        $this->assertTrue((bool) preg_grep('/UID STORE 4:4 \+FLAGS/', $this->serverLog()));
    }

    /**
     * An error in a folder other than INBOX is logged; the other folders are
     * still fetched.
     */
    public function testFolderErrorIsLogged()
    {
        $port = $this->startServer([
            'INBOX'   => [3 => $this->message(3, 'In the inbox')],
            'Archive' => [4 => $this->message(4, 'In the archive')],
        ], ['Archive:UID SEARCH' => 'Server busy']);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        $mailbox->in_imap_folders = json_encode(['Archive', 'INBOX']);
        $mailbox->save();

        $output = $this->fetch();

        $this->assertStringContainsString('Server busy', $output);
        $this->assertSame(['In the inbox'], Conversation::where('mailbox_id', $mailbox->id)->pluck('subject')->all());
        $errors = $this->fetchingErrors();
        $this->assertStringContainsString('Folder: Archive; Count error: NO Server busy', $errors[0]['error']);
        $this->assertStringContainsString('Folder: Archive; Error: NO Server busy', $errors[1]['error']);
        $this->assertSame('Fetched', $errors[0]['mailbox']);
        $this->assertNotEmpty(Option::get('fetch_emails_last_successful_run'));
    }

    /**
     * An INBOX that can't be read fails the mailbox's fetching ("Throw
     * exception for INBOX only"), so System Status shows fetching failing.
     */
    public function testInboxErrorFailsTheRun()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'In the inbox')]], ['INBOX:UID SEARCH' => 'Server busy']);
        $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $this->fetch();

        $this->assertEmpty(Option::get('fetch_emails_last_successful_run'));
    }

    public function testConnectionFailureIsLogged()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'Fetched anyway')]]);
        $this->imapMailbox(1, ['name' => 'Unreachable', 'email' => 'unreachable@example.org']);
        $working = $this->imapMailbox($port, ['name' => 'Working', 'email' => 'fetched@example.org']);

        $output = $this->fetch();

        $this->assertStringContainsString('Error: connection failed', $output);
        $errors = $this->fetchingErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('Unreachable', $errors[0]['mailbox']);
        $this->assertStringStartsWith('Error: connection failed', $errors[0]['error']);
        $this->assertSame(1, Conversation::where('mailbox_id', $working->id)->count(), 'The next mailbox is fetched.');
        $this->assertNotEmpty(Option::get('fetch_emails_last_run'));
        $this->assertEmpty(Option::get('fetch_emails_last_successful_run'));
    }

    /**
     * An email to this mailbox and another one (delivered by the mail
     * server, so not fetched) is saved into both.
     */
    public function testEmailToSeveralMailboxesIsImportedIntoEach()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'For both', ['cc' => 'sales@example.org'])]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        $sales = $this->createMailbox([], ['name' => 'Sales', 'email' => 'sales@example.org']);
        $sales->in_protocol = Mailbox::IN_PROTOCOL_MAIL_SERVER;
        $sales->save();

        $output = $this->fetch();

        $this->assertStringContainsString('Importing emails sent to several mailboxes at once: 1', $output);
        $this->assertStringContainsString('1) For both', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $mailbox->id)->count());
        $this->assertSame('For both', Conversation::where('mailbox_id', $sales->id)->value('subject'));
        $this->assertNotSame(
            Thread::whereHas('conversation', fn ($query) => $query->where('mailbox_id', $mailbox->id))->value('message_id'),
            Thread::whereHas('conversation', fn ($query) => $query->where('mailbox_id', $sales->id))->value('message_id')
        );
    }

    /**
     * An email webklex can't make is fetched raw, saved and marked as read.
     */
    public function testEmailWebklexCannotMakeIsFetchedRaw()
    {
        $raw = "Message-ID: <raw-4567@example.org>\n".file_get_contents(base_path('tests/Messages/issue-4567.eml'));
        $port = $this->startServer(['INBOX' => [
            3 => $this->message(3, 'Fine email'),
            4 => ['raw' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 'flags' => []],
        ]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $output = $this->fetch();

        $this->assertStringContainsString('Fetched: 2', $output);
        $this->assertSame(2, Conversation::where('mailbox_id', $mailbox->id)->count());
        $this->assertTrue(Thread::where('message_id', 'raw-4567@example.org')->exists());
        $this->assertTrue((bool) preg_grep('/UID STORE 4:4 \+FLAGS\.SILENT \(\\\\Seen\)/', $this->serverLog()));
    }

    /**
     * An email webklex can't make and that can't be fetched raw either is
     * logged; the others are saved.
     */
    public function testEmailThatCannotBeFetchedRawIsLogged()
    {
        $raw = "Message-ID: <raw-4567@example.org>\n".file_get_contents(base_path('tests/Messages/issue-4567.eml'));
        $port = $this->startServer(['INBOX' => [
            3 => $this->message(3, 'Fine email'),
            4 => ['raw' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 'flags' => []],
        ]], ['INBOX:UID FETCH 4:4 (RFC822.HEADER)' => 'Message gone']);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $this->fetch();

        $this->assertSame(['Fine email'], Conversation::where('mailbox_id', $mailbox->id)->pluck('subject')->all());
        $this->assertStringStartsWith('Folder: INBOX; Error fetching messages: ', $this->fetchingErrors()[0]['error']);
    }

    /**
     * Folders can't be listed: reported, nothing fetched.
     */
    public function testFoldersCannotBeListed()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'Unreachable email')]], [':LIST' => 'Not now']);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $output = $this->fetch();

        $this->assertStringContainsString('IMAP folder (INBOX) not found on the mail server: ', $output);
        $this->assertStringContainsString('IMAP folder not found on the mail server: INBOX', $output);
        $this->assertSame(0, Conversation::where('mailbox_id', $mailbox->id)->count());
    }

    /**
     * When a module adds incoming protocols, only mailboxes with the built-in
     * ones are fetched here.
     */
    public function testMailboxesWithModuleProtocolsAreLeftToTheModule()
    {
        \Eventy::addFilter('mailbox.in_protocols', function ($protocols) {
            return $protocols + [99 => 'custom'];
        }, 20, 1);
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'For IMAP')]]);
        $imap = $this->imapMailbox($port, ['name' => 'IMAP', 'email' => 'fetched@example.org']);
        $custom = $this->imapMailbox($port, ['name' => 'Custom', 'email' => 'custom@example.org']);
        $custom->in_protocol = 99;
        $custom->save();

        $output = $this->fetch();

        $this->assertStringContainsString('Mailbox: IMAP', $output);
        $this->assertStringNotContainsString('Mailbox: Custom', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $imap->id)->count());
    }

    public function testDebugMode()
    {
        $port = $this->startServer(['INBOX' => [3 => $this->message(3, 'Debugged email')]]);
        $mailbox = $this->imapMailbox($port, ['email' => 'fetched@example.org']);
        $level = ob_get_level();

        $output = $this->fetch(['--debug' => '1']);

        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString('1) Debugged email', $output);
        $this->assertSame(1, Conversation::where('mailbox_id', $mailbox->id)->count());
    }

    /**
     * --debug=1 shows the conversation with the IMAP server.
     */
    public function testDebugShowsImapCommands()
    {
        $port = $this->startServer(['INBOX' => []]);
        $this->imapMailbox($port, ['email' => 'fetched@example.org']);

        $output = $this->fetch(['--debug' => '1']);

        $this->assertStringContainsString('LOGIN', $output);
    }

    /**
     * With --debug=1, a mailbox that fails doesn't leave output buffering on
     * (which would hide what is printed afterwards).
     */
    public function testDebugWithFailingMailboxEndsOutputBuffering()
    {
        $this->imapMailbox(1, ['email' => 'unreachable@example.org']);
        $level = ob_get_level();

        $this->fetch(['--debug' => '1']);

        $this->assertSame($level, ob_get_level());
    }

    protected function fetchingErrors()
    {
        return \App\ActivityLog::where('log_name', \App\ActivityLog::NAME_EMAILS_FETCHING)->orderBy('id')->get()->map(function ($log) {
            return $log->properties->toArray();
        })->all();
    }
}

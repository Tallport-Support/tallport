<?php

namespace Tests\Feature;

use App\Mailbox;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Finding an email on the IMAP server by its Message-ID
 * (Thread::fetchBody()), against a local IMAP server started per test.
 */
class MailHelperImapTest extends FeatureTestCase
{
    protected $server;

    protected $server_pipes = [];

    protected function tearDown(): void
    {
        $this->stopServer();

        parent::tearDown();
    }

    /**
     * An IMAP server with the given folders. $messages: uid => raw email, in
     * every folder. $searches: how UID SEARCH is answered, by the first search
     * key: ['HEADER' => [uids], 'SINCE' => [uids] or 'NO'].
     *
     * @return int The port.
     */
    protected function startServer(array $messages, array $searches, array $folders = ['INBOX', 'Archive'])
    {
        $code = <<<'PHP'
$messages = json_decode($argv[1], true);
$searches = json_decode($argv[2], true);
$folders = json_decode($argv[3], true);
$server = stream_socket_server('tcp://127.0.0.1:0');
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);
$client = stream_socket_accept($server, 30);
fwrite($client, "* OK [CAPABILITY IMAP4rev1] Fake IMAP ready\r\n");
while (($line = fgets($client)) !== false) {
    echo trim($line)."\n";
    fflush(STDOUT);
    $parts = explode(' ', trim($line));
    $tag = $parts[0];
    $command = strtoupper($parts[1] ?? '');
    if ($command == 'UID') {
        $command = 'UID '.strtoupper($parts[2] ?? '');
    }
    $response = "$tag OK $command completed\r\n";
    switch ($command) {
        case 'CAPABILITY':
            $response = "* CAPABILITY IMAP4rev1\r\n".$response;
            break;
        case 'LIST':
            foreach (array_reverse($folders) as $folder) {
                $response = "* LIST (\\HasNoChildren) \"/\" \"$folder\"\r\n".$response;
            }
            break;
        case 'SELECT':
        case 'EXAMINE':
            $response = '* '.count($messages)." EXISTS\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n* OK [UIDNEXT 100] Predicted next UID\r\n$tag OK [READ-WRITE] $command completed\r\n";
            break;
        case 'UID SEARCH':
            $key = strtoupper($parts[3] ?? '');
            if ($key == 'CHARSET') {
                $key = strtoupper($parts[5] ?? '');
            }
            $result = $searches[$key] ?? [];
            if ($result === 'NO') {
                $response = "$tag NO [UNAVAILABLE] UID SEARCH Backend error\r\n";
            } else {
                $response = '* SEARCH'.($result ? ' '.implode(' ', $result) : '')."\r\n".$response;
            }
            break;
        case 'UID FETCH':
            $uids = [];
            foreach (explode(',', $parts[3]) as $uid) {
                $uids[] = (int) $uid;
            }
            $what = strtoupper(implode(' ', array_slice($parts, 4)));
            $out = '';
            foreach ($uids as $n => $uid) {
                if (!isset($messages[$uid])) {
                    continue;
                }
                [$header, $body] = explode("\r\n\r\n", $messages[$uid], 2);
                $header .= "\r\n\r\n";
                $seq = $n + 1;
                if (strpos($what, 'RFC822.HEADER') !== false) {
                    $out .= "* $seq FETCH (UID $uid RFC822.HEADER {".strlen($header)."}\r\n$header)\r\n";
                } elseif (strpos($what, 'RFC822.TEXT') !== false) {
                    $out .= "* $seq FETCH (UID $uid RFC822.TEXT {".strlen($body)."}\r\n$body)\r\n";
                } elseif (strpos($what, 'FLAGS') !== false) {
                    $out .= "* $seq FETCH (UID $uid FLAGS (\\Seen))\r\n";
                } elseif (strpos($what, 'RFC822.SIZE') !== false) {
                    $out .= "* $seq FETCH (UID $uid RFC822.SIZE ".strlen($messages[$uid]).")\r\n";
                } else {
                    $out .= "* $seq FETCH (UID $uid)\r\n";
                }
            }
            $response = $out.$response;
            break;
        case 'LOGOUT':
            fwrite($client, "* BYE Logging out\r\n$response");
            break 2;
    }
    fwrite($client, $response);
}
fclose($client);
PHP;
        $this->server = proc_open([PHP_BINARY, '-r', $code, '--', json_encode($messages), json_encode($searches), json_encode($folders)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->server_pipes);

        return (int) fgets($this->server_pipes[1]);
    }

    /**
     * Commands the server received, once it has stopped.
     */
    protected function stopServer()
    {
        if (!$this->server) {
            return [];
        }
        gc_collect_cycles();
        stream_set_blocking($this->server_pipes[1], false);
        usleep(100000);
        $commands = array_filter(explode("\n", stream_get_contents($this->server_pipes[1])));
        proc_terminate($this->server);
        proc_close($this->server);
        $this->server = null;

        return array_values($commands);
    }

    protected function imapMailbox($port, array $folders = [])
    {
        $mailbox = $this->createMailbox([$this->createUser()]);
        $mailbox->fill(['in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => '127.0.0.1', 'in_port' => $port, 'in_username' => 'u', 'in_password' => 'p', 'in_encryption' => Mailbox::IN_ENCRYPTION_NONE])->save();
        if ($folders) {
            $mailbox->setInImapFolders($folders);
            $mailbox->save();
        }

        return $mailbox;
    }

    protected function rawEmail($message_id, $subject)
    {
        return "From: Casey <casey@customer.example.org>\r\nTo: support@example.org\r\nSubject: $subject\r\nDate: Tue, 6 Oct 2026 10:00:00 +0000\r\nMessage-ID: <$message_id>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of $subject\r\n";
    }

    public function testFoundBySearchingForTheMessageId()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => [5]]);
        $mailbox = $this->imapMailbox($port);

        $message = \MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00'));

        $this->assertSame('Wanted', $message->getSubject()->toString());
        $this->assertStringContainsString('Body of Wanted', $message->getTextBody());
        $commands = $this->stopServer();
        $this->assertNotEmpty(preg_grep('/ UID SEARCH HEADER Message-ID "<wanted@customer.example.org>" SINCE "29-Sep-2026" BEFORE "13-Oct-2026"$/', $commands));
        $this->assertEmpty(preg_grep('/ STORE /', $commands), 'The message is left unread.');
    }

    public function testWithoutDateTheWholeFolderIsSearched()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => [5]]);
        $mailbox = $this->imapMailbox($port);

        $message = \MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org');

        $this->assertSame('Wanted', $message->getSubject()->toString());
        $this->assertNotEmpty(preg_grep('/UID SEARCH HEADER Message-ID "<wanted@customer.example.org>"$/', $this->stopServer()));
    }

    /**
     * Folders to search come from the mailbox (and the mail.fetch_message.imap_folders
     * filter); a folder the server doesn't have is skipped.
     */
    public function testSearchesEveryFetchedFolder()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => [5]], ['INBOX', 'Archive']);
        $mailbox = $this->imapMailbox($port, ['Missing', 'Archive']);
        \Log::spy();

        $message = \MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org');

        $this->assertSame('Wanted', $message->getSubject()->toString());
        $this->assertNotEmpty(preg_grep('/ SELECT "Archive"$/', $this->stopServer()));
        \Log::shouldHaveReceived('error')->with('('.$mailbox->name.') Show Original - folder not found: Missing');
    }

    /**
     * Servers such as imap.yandex.ru reject a search by Message-ID: the
     * headers of the messages around the email's date are compared instead.
     */
    public function testFoundByScanningHeadersWhenTheSearchIsRejected()
    {
        $port = $this->startServer([
            3 => $this->rawEmail('other@customer.example.org', 'Other'),
            5 => $this->rawEmail('wanted@customer.example.org', 'Wanted'),
        ], ['HEADER' => 'NO', 'SINCE' => [3, 5]]);
        $mailbox = $this->imapMailbox($port);

        $message = \MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00'));

        $this->assertSame('Wanted', $message->getSubject()->toString());
        $commands = $this->stopServer();
        $this->assertNotEmpty(preg_grep('/UID SEARCH SINCE "06-Oct-2026" BEFORE "07-Oct-2026"$/', $commands));
        $this->assertNotEmpty(preg_grep('/UID FETCH 3,5 \(RFC822.HEADER\)$/', $commands));
        $this->assertEmpty(preg_grep('/ STORE /', $commands), 'The message is left unread.');
    }

    public function testScanTriesTheNeighbouringDays()
    {
        $port = $this->startServer([5 => $this->rawEmail('other@customer.example.org', 'Other')], ['HEADER' => 'NO', 'SINCE' => [5]]);
        $mailbox = $this->imapMailbox($port);

        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00')));

        $commands = $this->stopServer();
        $this->assertNotEmpty(preg_grep('/UID SEARCH SINCE "06-Oct-2026" BEFORE "07-Oct-2026"$/', $commands));
        $this->assertNotEmpty(preg_grep('/UID SEARCH SINCE "05-Oct-2026" BEFORE "08-Oct-2026"$/', $commands));
    }

    /**
     * mail.fetch_message.scan_limit: at most that many messages are scanned
     * per date range.
     */
    public function testScanStopsAtTheLimit()
    {
        $port = $this->startServer([
            3 => $this->rawEmail('other@customer.example.org', 'Other'),
            5 => $this->rawEmail('wanted@customer.example.org', 'Wanted'),
        ], ['HEADER' => 'NO', 'SINCE' => [3, 5]]);
        $mailbox = $this->imapMailbox($port);
        \Eventy::addFilter('mail.fetch_message.scan_limit', function () {
            return 1;
        });
        \Log::spy();

        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00')));

        $this->assertEmpty(preg_grep('/UID FETCH 3,5 /', $this->stopServer()));
        \Log::shouldHaveReceived('error')->with('('.$mailbox->name.') MailHelper::findMessageByHeaders(): the number of messages to scan in "INBOX" exceeds the limit (1), message not found: wanted@customer.example.org');
    }

    /**
     * Without a date the whole mailbox would have to be scanned.
     */
    public function testNoScanWithoutDate()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => 'NO', 'SINCE' => [5]]);
        $mailbox = $this->imapMailbox($port);

        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org'));

        $commands = $this->stopServer();
        $this->assertNotEmpty(preg_grep('/UID SEARCH HEADER/', $commands));
        $this->assertEmpty(preg_grep('/UID SEARCH SINCE/', $commands));
    }

    public function testNoScanWithZeroLimit()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => 'NO', 'SINCE' => [5]]);
        $mailbox = $this->imapMailbox($port);
        \Eventy::addFilter('mail.fetch_message.scan_limit', function () {
            return 0;
        });

        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00')));

        $commands = $this->stopServer();
        $this->assertNotEmpty(preg_grep('/UID SEARCH HEADER/', $commands));
        $this->assertEmpty(preg_grep('/UID SEARCH SINCE/', $commands));
    }

    /**
     * A folder the server doesn't have is skipped, a server rejecting every
     * search is logged, and nothing is found.
     */
    public function testScanOfServerRejectingEverySearch()
    {
        $port = $this->startServer([5 => $this->rawEmail('wanted@customer.example.org', 'Wanted')], ['HEADER' => 'NO', 'SINCE' => 'NO']);
        $mailbox = $this->imapMailbox($port, ['Missing', 'INBOX']);
        \Log::spy();

        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org', Carbon::parse('2026-10-06 10:00:00')));

        $this->assertNotEmpty(preg_grep('/UID SEARCH SINCE/', $this->stopServer()));
        \Log::shouldHaveReceived('error')->withArgs(function ($message) use ($mailbox) {
            return str_starts_with($message, '('.$mailbox->name.') Could not find specific message by Message-ID in headers:');
        });
    }

    public function testNothingWithoutMessageIdOrConnection()
    {
        $mailbox = $this->imapMailbox(1);

        $this->assertNull(\MailHelper::fetchMessage($mailbox, ''));
        $this->assertNull(\MailHelper::fetchMessage($mailbox, 'wanted@customer.example.org'));
    }

    public function testConnectionTestWithoutInbox()
    {
        $port = $this->startServer([], [], ['Archive']);
        $mailbox = $this->imapMailbox($port);

        ob_start();
        $result = \MailHelper::fetchTest($mailbox);
        $this->stopServer();
        $output = ob_get_clean();

        $this->assertSame('error', $result['result']);
        $this->assertSame('Could not get mailbox folder: INBOX', $result['msg']);
        $this->assertStringContainsString('LOGIN', $result['log'], 'The IMAP conversation is shown.');
        $this->assertSame('', $output);
    }
}

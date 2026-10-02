<?php

/**
 * A minimal IMAP server for tests: one connection, an empty INBOX.
 * Prints the port it listens on, then serves until the client logs out.
 *
 * Usage: php fake-imap-server.php
 */
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, $errstr."\n");
    exit(1);
}
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 30);
if (!$client) {
    exit(1);
}
fwrite($client, "* OK [CAPABILITY IMAP4rev1] Fake IMAP ready\r\n");

while (($line = fgets($client)) !== false) {
    [$tag, $command] = array_pad(explode(' ', trim($line), 3), 2, '');
    $command = strtoupper($command);
    if ($command == 'UID') {
        $command = 'UID '.strtoupper(explode(' ', trim($line))[2] ?? '');
    }

    switch ($command) {
        case 'CAPABILITY':
            $response = "* CAPABILITY IMAP4rev1\r\n$tag OK CAPABILITY completed\r\n";
            break;
        case 'LIST':
            $response = "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n$tag OK LIST completed\r\n";
            break;
        case 'SELECT':
        case 'EXAMINE':
            $response = "* 0 EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n* OK [UIDNEXT 1] Predicted next UID\r\n$tag OK [READ-WRITE] $command completed\r\n";
            break;
        case 'SEARCH':
        case 'UID SEARCH':
            $response = "* SEARCH\r\n$tag OK SEARCH completed\r\n";
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

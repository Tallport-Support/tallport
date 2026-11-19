<?php

/**
 * A minimal SMTP server for tests that, like real ones, closes a connection the client leaves
 * idle for longer than its timeout (the first argument, in seconds), saying so first.
 * Prints the port it listens on, then "message" for each message it accepts.
 *
 * Usage: php fake-smtp-server.php [timeout]
 */
$timeout = (float) ($argv[1] ?? 1);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, $errstr."\n");
    exit(1);
}
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);

while ($client = @stream_socket_accept($server, 30)) {
    fwrite($client, "220 Fake ESMTP ready\r\n");
    $in_data = false;
    while (true) {
        // Lines already read into PHP's buffer don't wake stream_select().
        if (!stream_get_meta_data($client)['unread_bytes']) {
            $read = [$client];
            $write = $except = [];
            if (!stream_select($read, $write, $except, (int) $timeout, (int) (fmod($timeout, 1) * 1000000))) {
                fwrite($client, "451 4.4.2 Timeout waiting for data from client.\r\n");
                break;
            }
        }
        $line = fgets($client);
        if ($line === false) {
            break;
        }
        if ($in_data) {
            if ($line === ".\r\n") {
                $in_data = false;
                fwrite($client, "250 2.0.0 Ok: queued\r\n");
                echo "message\n";
                fflush(STDOUT);
            }
            continue;
        }
        $command = strtoupper(substr(trim($line), 0, 4));
        if ($command == 'DATA') {
            $in_data = true;
            fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($command == 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        } else {
            fwrite($client, "250 Ok\r\n");
        }
    }
    fclose($client);
}

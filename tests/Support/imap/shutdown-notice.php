<?php
// Parses an address the imap extension rejects, then ends the request. Run by
// ImapShutdownNoticeTest in a separate process: the extension reports the
// error at request shutdown, after the test would have finished.
require __DIR__.'/../../../vendor/autoload.php';

set_error_handler(function ($level, $message) {
    echo 'error: '.$message."\n";

    return true;
});

if (($argv[1] ?? '') === 'clear') {
    \App\Misc\Mail::clearImapErrorsOnShutdown();
}

imap_rfc822_parse_headers("To: <>\r\n");
echo "parsed\n";

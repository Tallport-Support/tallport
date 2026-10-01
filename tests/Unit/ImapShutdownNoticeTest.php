<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * @requires extension imap
 */
class ImapShutdownNoticeTest extends TestCase
{
    protected function runScript($argument)
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../Support/imap/shutdown-notice.php').' '.$argument.' 2>&1', $output);

        return implode("\n", $output);
    }

    public function testImapErrorsAreClearedBeforeShutdown()
    {
        // Without clearing, the extension reports the error when the request ends.
        $this->assertStringContainsString('error: PHP Request Shutdown', $this->runScript('none'));

        $this->assertSame('parsed', $this->runScript('clear'));
    }
}

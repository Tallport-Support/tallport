<?php

namespace Tests\Feature;

/**
 * The same emails fetched with the webklex/php-imap 6 client
 * (APP_FETCH_CLIENT=webklex6) are saved exactly the same.
 */
class IncomingMailSnapshotWebklex6ClientTest extends IncomingMailSnapshotTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.fetch_client' => 'webklex6']);
    }
}

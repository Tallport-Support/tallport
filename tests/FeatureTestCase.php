<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesModels;
use Tests\Concerns\InteractsWithMail;

/**
 * Base for tests that exercise the application end to end: HTTP requests,
 * email in and out, the database. Each test runs in a transaction that is
 * rolled back afterwards, and outgoing email is captured.
 */
abstract class FeatureTestCase extends TestCase
{
    use DatabaseTransactions;
    use CreatesModels;
    use InteractsWithMail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureSentMail();
    }

    /**
     * POST to an ajax endpoint the way the frontend does.
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function postAjax($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge($data, [
            '_token' => csrf_token(),
        ]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }
}

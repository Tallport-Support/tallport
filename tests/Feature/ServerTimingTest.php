<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/**
 * The Server-Timing header (App\Http\Middleware\ServerTiming): for signed-in users only.
 */
class ServerTimingTest extends FeatureTestCase
{
    public function testSignedInUsersGetTheServerTiming()
    {
        $this->get(route('login'))->assertOk()->assertHeaderMissing('Server-Timing');

        $user = $this->createUser();
        $timing = $this->actingAs($user)->get(route('users.preferences', ['id' => $user->id]))->headers->get('Server-Timing');
        $this->assertMatchesRegularExpression('#^app;dur=[\d.]+, boot;dur=[\d.]+, db;dur=[\d.]+;desc="\d+ queries"$#', (string) $timing);
    }
}

<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Timestamps with fractions or a time zone (as PostgreSQL returns them) are
 * read as plain database times.
 */
class DatabaseDateTest extends TestCase
{
    public function testModelReadsTimestampWithFraction()
    {
        $customer = new \App\Customer();
        $customer->setRawAttributes(['created_at' => '2026-03-05 14:07:09.123456']);

        $this->assertSame('2026-03-05 14:07:09', $customer->created_at->format('Y-m-d H:i:s'));
    }
}

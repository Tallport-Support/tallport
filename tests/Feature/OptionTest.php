<?php

namespace Tests\Feature;

use App\Option;
use Tests\FeatureTestCase;

/**
 * Options are cached per process; the cache must follow changes (S8).
 */
class OptionTest extends FeatureTestCase
{
    public function testReadsSeeChangesInTheSameProcess()
    {
        Option::$cache = [];
        $this->assertSame('fallback', Option::get('tallport_test_option', 'fallback'));

        Option::set('tallport_test_option', 'first');
        $this->assertSame('first', Option::get('tallport_test_option', 'fallback'));

        Option::set('tallport_test_option', ['a' => 1]);
        $this->assertSame(['a' => 1], Option::get('tallport_test_option', 'fallback'));

        Option::remove('tallport_test_option');
        $this->assertSame('fallback', Option::get('tallport_test_option', 'fallback'));
    }
}

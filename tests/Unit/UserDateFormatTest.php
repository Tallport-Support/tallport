<?php

namespace Tests\Unit;

use App\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * User::dateFormat() turns PHP date formats into ICU patterns and formats
 * with IntlDateFormatter in the user's time zone and 12/24h preference.
 */
class UserDateFormatTest extends TestCase
{
    protected function user(array $attributes = [])
    {
        return new User(array_merge(['timezone' => 'Europe/Amsterdam', 'time_format' => User::TIME_FORMAT_24], $attributes));
    }

    /**
     * @dataProvider formats
     */
    public function testDateFormat($format, $time_format, $expected)
    {
        config(['app.locale' => 'en', 'app.timezone' => 'UTC']);
        $date = Carbon::create(2026, 3, 5, 14, 7, 9, 'UTC');

        $this->assertSame($expected, User::dateFormat($date, $format, $this->user(['time_format' => $time_format])));
    }

    public function formats()
    {
        return [
            'default 24h'  => ['M j, Y H:i', User::TIME_FORMAT_24, 'Mar 5, 2026 15:07'],
            'default 12h'  => ['M j, Y H:i', User::TIME_FORMAT_12, 'Mar 5, 2026 03:07pm'],
            'weekday'      => ['l, M j', User::TIME_FORMAT_24, 'Thursday, Mar 5'],
            'numeric'      => ['d.m.Y', User::TIME_FORMAT_24, '05.03.2026'],
        ];
    }

    /**
     * @dataProvider diffs
     */
    public function testDateDiffForHumans($seconds_ago, $expected)
    {
        config(['app.locale' => 'en', 'app.timezone' => 'UTC']);
        $date = Carbon::now()->subSeconds($seconds_ago);

        $this->assertSame($expected($date), User::dateDiffForHumans($date));
    }

    public function diffs()
    {
        return [
            'seconds ago'          => [30, function () { return 'Just now'; }],
            'a minute in future'   => [-30, function () { return 'Just now'; }],
            'minutes ago'          => [5 * 60, function () { return '5 min ago'; }],
            'seven and a half days' => [(7 * 24 + 12) * 3600, function () { return '1 week ago'; }],
            'eight days ago'       => [8 * 24 * 3600, function ($date) { return $date->format('M j'); }],
        ];
    }

    public function testWithoutUserUsesTheFormatAsIs()
    {
        config(['app.locale' => 'en', 'app.timezone' => 'UTC']);
        $this->assertSame('Mar 5, 2026 14:07', User::dateFormat(Carbon::create(2026, 3, 5, 14, 7, 9, 'UTC'), 'M j, Y H:i', false));
    }
}

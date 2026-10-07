<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Option;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * tallport:logs-monitor: which log records the alert email reports, and
 * the transient mail fetching errors it leaves out (Settings » Alerts).
 */
class LogsMonitorTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAdmin(['email' => 'boss@example.org']);
        $this->setOption('alert_logs_names', [ActivityLog::NAME_EMAILS_FETCHING, ActivityLog::NAME_EMAILS_SENDING]);
        $this->setOption('alert_logs_period', 'hour');
    }

    protected function setOption($name, $value)
    {
        Option::set($name, $value);
        Option::$cache = [];
    }

    protected function logFetchError($error, $times = 1)
    {
        for ($i = 0; $i < $times; $i++) {
            activity()->useLog(ActivityLog::NAME_EMAILS_FETCHING)->withProperties(['error' => $error, 'mailbox' => 'Support'])->log('error_fetching_email');
        }
    }

    protected function runMonitor()
    {
        // The monitor only reports records from before "now".
        \DB::table('activity_logs')->where('created_at', '>=', Carbon::now()->subMinute())->update(['created_at' => Carbon::now()->subMinute()]);
        \Artisan::call('tallport:logs-monitor');

        return \Artisan::output();
    }

    public function testRareTransientFetchErrorsAreLeftOut()
    {
        $this->setOption('alert_logs_fetch_min_occurrences', 3);
        // Twice, with different file details: still the same error, below the threshold.
        $this->logFetchError('Connection refused; File: /a/b.php (1)');
        $this->logFetchError('Connection refused; File: /a/b.php (2)');
        $this->logFetchError('Connection timed out', 3);
        $this->logFetchError('Mailbox password is wrong');
        activity()->useLog(ActivityLog::NAME_EMAILS_SENDING)->withProperties(['error' => 'Connection refused'])->log('error_sending_email_to_customer');

        $output = $this->runMonitor();

        $this->assertStringContainsString('Suppressed 2 transient fetch_errors entries (threshold 3)', $output);
        $body = $this->sentEmailsTo('boss@example.org')[0]->getBody();
        $this->assertStringNotContainsString('(1)', $body);
        $this->assertStringNotContainsString('(2)', $body);
        $this->assertSame(3, substr_count($body, 'Connection timed out'));
        $this->assertStringContainsString('Mailbox password is wrong', $body);
        // Other logs aren't filtered.
        $this->assertStringContainsString('Connection refused', $body);
    }

    public function testNothingLeftMeansNoEmail()
    {
        $this->setOption('alert_logs_fetch_min_occurrences', 5);
        $this->logFetchError('SSL operation failed with code 1', 4);

        $output = $this->runMonitor();

        $this->assertStringContainsString('No new log records found for the last hour', $output);
        $this->assertCount(0, $this->sentEmails());
    }

    public function testEveryFetchErrorIsReportedByDefault()
    {
        $this->logFetchError('Connection reset by peer');

        $this->runMonitor();

        $this->assertStringContainsString('Connection reset by peer', $this->sentEmailsTo('boss@example.org')[0]->getBody());
    }

    public function testNeedsAPeriod()
    {
        config(['app.alert_logs_period' => '']);
        $this->setOption('alert_logs_period', '');
        $this->logFetchError('Connection reset by peer');

        $this->assertStringContainsString('No logs monitoring period set', $this->runMonitor());
        $this->assertCount(0, $this->sentEmails());
    }
}

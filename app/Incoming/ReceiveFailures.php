<?php

namespace App\Incoming;

/**
 * Emails tallport:receive could not save. The mail server keeps them and
 * delivers them again; until that succeeds, agents see a warning and the
 * "Fetching problems" alert (Manage » Alerts) is sent.
 */
class ReceiveFailures
{
    const OPTION = 'receive_failures';

    /**
     * Postfix gives up after 5 days (maximal_queue_lifetime) and bounces.
     */
    const KEEP_DAYS = 7;

    /**
     * Failed emails not received since: [hash of the source => time of the first failure].
     */
    public static function all()
    {
        $failures = \Option::get(self::OPTION, []);
        if (!is_array($failures)) {
            return [];
        }

        return array_filter($failures, function ($time) {
            return $time > time() - self::KEEP_DAYS * 86400;
        });
    }

    public static function failed($raw)
    {
        $failures = self::all();
        if (!$failures && \Option::get('alert_fetch')) {
            \MailHelper::sendAlertMail('An email from the mail server could not be saved (tallport:receive); the mail server will deliver it again. Please check the <a href="'.route('logs', ['name' => 'fetch_errors']).'">fetching logs</a>.', 'Receiving Problems');
        }
        $failures[md5($raw)] = $failures[md5($raw)] ?? time();
        \Option::set(self::OPTION, $failures);
    }

    /**
     * The email has been saved (or will not be delivered again).
     */
    public static function received($raw)
    {
        $failures = self::all();
        if (!isset($failures[md5($raw)])) {
            return;
        }
        unset($failures[md5($raw)]);
        \Option::set(self::OPTION, $failures);
        if (!$failures && \Option::get('alert_fetch')) {
            \MailHelper::sendAlertMail('Emails that could not be saved before have been received now.', 'Receiving Recovered');
        }
    }
}

<?php

namespace App\Misc;

/**
 * Queue ID the SMTP server assigned to a sent message ("250 2.0.0 Ok:
 * queued as 4Xyz"), for the send log.
 * https://github.com/freescout-helpdesk/freescout/issues/3330
 */
class SwiftGetSmtpQueueId
{
    public static $last_smtp_queue_id = null;

    /**
     * Read the queue ID from the SMTP transcript of a sent message.
     *
     * @param \Illuminate\Mail\SentMessage|\Symfony\Component\Mailer\SentMessage|null $sent
     *
     * @return string|null
     */
    public static function fromSentMessage($sent)
    {
        self::$last_smtp_queue_id = null;

        if ($sent instanceof \Illuminate\Mail\SentMessage) {
            $sent = $sent->getSymfonySentMessage();
        }
        if (!$sent instanceof \Symfony\Component\Mailer\SentMessage) {
            return null;
        }

        $response_text = $sent->getDebug();
        if (strpos($response_text, 'queued') !== false) {
            preg_match("#queued as ([^\$\r\n ]+)[$\r\n]#", $response_text, $m);
            if (!empty($m[1]) && trim($m[1])) {
                self::$last_smtp_queue_id = trim($m[1]);
            }
        }

        return self::$last_smtp_queue_id;
    }
}

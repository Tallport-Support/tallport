<?php

namespace App\Misc;

use App\Email;
use App\Http\Controllers\AttachmentsController;
use App\Incoming\DeliveryReport;
use App\SendLog;
use App\Thread;

/**
 * Delivery reports (bounces, delays, complaints, suppression notices, delivery notices) in the
 * conversation: what a report thread says (send_status_data's delivery_report,
 * App\Incoming\DeliveryReport::toArray()), and the addresses emails couldn't
 * reach (emails.delivery_problem), which the customer's profile shows and the
 * composer warns about.
 */
class DeliveryReports
{
    /**
     * How long a report on a sent email's recipient counts as the same report,
     * whether it came as an email or from the sending service's webhook
     * (Amazon SES sends both): recorded and shown once.
     */
    const SAME_REPORT_HOURS = 72;

    /**
     * The send log's note for a delay (the others are "Message bounced" and "Complaint").
     */
    const DELAYED_LOG_MESSAGE = 'Delivery delayed';

    /**
     * A thread's delivery report, or null.
     */
    public static function forThread(Thread $thread)
    {
        $report = $thread->getSendStatusData()['delivery_report'] ?? null;

        return is_array($report) && !empty($report['kind']) && (!empty($report['recipients']) || $report['kind'] == DeliveryReport::DELIVERED) ? $report : null;
    }

    /**
     * What happened, with the recipient (HTML) in it.
     */
    public static function headline($kind, $recipient_html)
    {
        switch ($kind) {
            case DeliveryReport::DELAYED:
                return __('Delivery delayed to :recipient', ['recipient' => $recipient_html]);
            case DeliveryReport::COMPLAINT:
                return __('Complaint from :recipient', ['recipient' => $recipient_html]);
            case DeliveryReport::SUPPRESSED:
                return __('Not sent to :recipient', ['recipient' => $recipient_html]);
            case DeliveryReport::DELIVERED:
                return $recipient_html !== '' ? __('Delivered to :recipient', ['recipient' => $recipient_html]) : __('Delivered');
            default:
                return __('Not delivered to :recipient', ['recipient' => $recipient_html]);
        }
    }

    /**
     * The original email kept with a report thread (its .eml attachment), for reading in
     * place: ['html' => its body, cleaned as message bodies are and without images from
     * other servers, 'attachments' => the thread's files that came with it]; or null.
     */
    public static function originalBody(Thread $thread)
    {
        $file = $thread->attachments->first(fn ($attachment) => AttachmentsController::isEmail($attachment));
        if (!$file) {
            return null;
        }
        try {
            $original = \App\Incoming\Parser::parse((string) $file->getFileContents());
        } catch (\Throwable $e) {
            return null;
        }

        // Images it refers to (cid:) come from the attached email.
        $html = $original->htmlBody();
        $names = [];
        foreach (array_values($original->attachments()) as $i => $part) {
            $cid = trim((string) $part->id, '<>');
            if ($html !== '' && $cid !== '' && str_contains($html, 'cid:'.$cid)) {
                $html = str_replace('cid:'.$cid, route('attachments.email', ['id' => $file->id, 'part' => $i]), $html);
            } else {
                $names[] = $part->getName();
            }
        }
        $html = $html !== '' ? $thread->getBodyWithFormatedLinks($html) : nl2br(e((string) $original->textBody()));

        return [
            'html'        => ExternalImages::block($html)[0],
            'attachments' => $thread->attachments->filter(fn ($attachment) => $attachment->id != $file->id && in_array($attachment->file_name, $names))->values(),
        ];
    }

    /**
     * A kind as a short label.
     */
    public static function kindLabel($kind)
    {
        switch ($kind) {
            case DeliveryReport::DELAYED:
                return __('Delayed');
            case DeliveryReport::COMPLAINT:
                return __('Complaint');
            case DeliveryReport::SUPPRESSED:
                return __('Suppressed');
            case DeliveryReport::DELIVERED:
                return __('Delivered');
            default:
                return __('Not Delivered');
        }
    }

    /**
     * A reason (DeliveryReport::REASONS) in words.
     */
    public static function reasonText($reason)
    {
        switch ($reason) {
            case 'unknown_address':
                return __('The address does not exist.');
            case 'unknown_domain':
                return __('The domain of the address does not exist or does not receive email.');
            case 'mailbox_full':
                return __('The mailbox is full.');
            case 'mailbox_disabled':
                return __('The mailbox has been disabled.');
            case 'too_large':
                return __('The email was too large.');
            case 'blocked':
                return __('The receiving server refused the email as spam or because of its rules.');
            case 'unreachable':
                return __('The receiving server could not be reached.');
            case 'delayed':
                return __('Delivery is delayed; the sending server keeps trying.');
            case 'complaint':
                return __('The recipient marked the email as spam.');
            case 'suppressed':
                return __('The address is on the sending service\'s suppression list after an earlier bounce or complaint, so the email was not sent.');
            case 'delivered':
                return __('The recipient\'s mail server or mailbox confirmed that the email arrived.');
            default:
                return __('The receiving server rejected the email.');
        }
    }

    /**
     * Mark the report's addresses as not reached. A delay isn't: the email may
     * still arrive; nor is a delivery notice.
     */
    public static function flag(array $report, Thread $thread)
    {
        if (in_array($report['kind'], [DeliveryReport::DELAYED, DeliveryReport::DELIVERED])) {
            return;
        }
        foreach ($report['recipients'] as $recipient) {
            $email = Email::where('email', $recipient)->first();
            if (!$email) {
                continue;
            }
            $email->delivery_problem = [
                'at'              => now()->toDateTimeString(),
                'kind'            => $report['kind'],
                'reason'          => $report['reason'],
                'status'          => $report['status'],
                'thread_id'       => $thread->id,
                'conversation_id' => $thread->conversation_id,
            ];
            $email->save();
        }
    }

    /**
     * The sent reply (or, for an auto reply, the customer's message) an email
     * with this Message-ID was, or null. Tallport's own Message-ID only with
     * its hash, so that a made-up report can't mark any reply; else the ID the
     * sending service gave it (SendLog::threadForProviderMessageId()).
     */
    public static function sentThread($message_id)
    {
        $message_id = trim((string) $message_id, " <>\t");
        if ($message_id === '') {
            return null;
        }
        $prefixes = [];
        foreach ([\MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER, \MailHelper::MESSAGE_ID_PREFIX_AUTO_REPLY] as $prefix) {
            $prefixes[] = str_replace('TP_', '(?:TP_|'.\MailHelper::LEGACY_MESSAGE_ID_PREFIX.')?', $prefix);
        }
        if (preg_match('/^('.implode('|', $prefixes).')\-(\d+)\-([a-z0-9]+)@/', $message_id, $matches)) {
            return $matches[3] === \MailHelper::getMessageIdHash($matches[2]) ? Thread::find($matches[2]) : null;
        }

        return SendLog::threadForProviderMessageId($message_id);
    }

    /**
     * Whether a report of this kind about these recipients of the sent reply
     * (or auto reply) has been recorded within SAME_REPORT_HOURS. A bounce
     * and a suppression count as the same.
     */
    public static function isRecorded(Thread $reply, array $recipients, $kind = DeliveryReport::BOUNCE)
    {
        $query = SendLog::where('thread_id', $reply->id)
            ->whereIn('email', $recipients)
            ->where('created_at', '>=', now()->subHours(self::SAME_REPORT_HOURS));
        if ($kind == DeliveryReport::DELAYED) {
            $query->where('status', SendLog::STATUS_ACCEPTED)->where('status_message', 'like', self::DELAYED_LOG_MESSAGE.'%');
        } elseif ($kind == DeliveryReport::DELIVERED) {
            $query->where('status', SendLog::STATUS_DELIVERY_SUCCESS);
        } else {
            $query->where('status', $kind == DeliveryReport::COMPLAINT ? SendLog::STATUS_COMPLAINED : SendLog::STATUS_DELIVERY_ERROR);
        }

        return $query->exists();
    }

    /**
     * A sending service's report (its webhook, in the shape of
     * DeliveryReport::toArray()) about a sent reply or auto reply: the
     * addresses are flagged and the reply marked (markReply()). Once: false
     * when recorded already.
     */
    public static function recordFromService(Thread $reply, array $report, $mail_type = SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER)
    {
        if (!$report['recipients'] || self::isRecorded($reply, $report['recipients'], $report['kind'])) {
            return false;
        }

        self::flag($report, $reply);
        self::markReply($reply, $report, $mail_type);

        return true;
    }

    /**
     * Mark the sent reply (or auto reply) as a report about it says, and log
     * it for each recipient. A bounce or suppression: Not delivered. A
     * complaint: Reported as spam (it was delivered, so its send status
     * stays). A delay: Delivery delayed, unless it is Not delivered already.
     * A delivery notice: Delivered, unless it is Not delivered already.
     * $report_thread: the report email's thread, when it came as an email.
     */
    public static function markReply(Thread $reply, array $report, $mail_type = SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, ?Thread $report_thread = null)
    {
        $notice = [
            'kind'       => $report['kind'],
            'recipients' => $report['recipients'],
            'reason'     => $report['reason'] ?? '',
            'reporter'   => $report['reporter'] ?? '',
            'at'         => now()->toDateTimeString(),
        ];
        if ($report_thread) {
            $notice['thread_id'] = $report_thread->id;
            $notice['conversation_id'] = $report_thread->conversation_id;
        }

        if ($report['kind'] == DeliveryReport::COMPLAINT) {
            $reply->updateSendStatusData(['complaint' => $notice]);
            $status = SendLog::STATUS_COMPLAINED;
            $log_message = 'Complaint';
        } elseif ($report['kind'] == DeliveryReport::DELAYED) {
            if (!$reply->isSendStatusError()) {
                $reply->updateSendStatusData(['delivery_delayed' => $notice]);
            }
            $status = SendLog::STATUS_ACCEPTED;
            $log_message = self::DELAYED_LOG_MESSAGE;
        } elseif ($report['kind'] == DeliveryReport::DELIVERED) {
            if (!$reply->isSendStatusError()) {
                $reply->updateSendStatusData(['delivered' => $notice]);
            }
            $status = SendLog::STATUS_DELIVERY_SUCCESS;
            $log_message = 'Delivered';
        } else {
            $reply->send_status = SendLog::STATUS_DELIVERY_ERROR;
            if ($report_thread) {
                $reply->updateSendStatusData([
                    'bounced_by_thread'       => $report_thread->id,
                    'bounced_by_conversation' => $report_thread->conversation_id,
                ]);
            } else {
                $reply->updateSendStatusData(['delivery_problem' => $report]);
            }
            $status = SendLog::STATUS_DELIVERY_ERROR;
            $log_message = 'Message bounced';
        }
        $reply->save();

        if (!empty($report['reporter'])) {
            $log_message .= ' ('.$report['reporter'].')';
        }
        if (!empty($report['diagnostic'])) {
            $log_message .= ': '.$report['diagnostic'];
        }
        // One record per address it's about, which a later report on it finds (isRecorded()).
        foreach ($report['recipients'] as $recipient) {
            SendLog::log($reply->id, null, $recipient, $mail_type, $status, $reply->created_by_customer_id, null, $log_message);
        }
    }

    /**
     * A sent reply's delivery notice that isn't a failure: a complaint, a
     * delivery or a delay (markReply()): ['kind', 'text', 'details' => who
     * reported it, when, and the report email's conversation (HTML)], or null.
     */
    public static function replyNotice(Thread $reply)
    {
        $data = $reply->getSendStatusData();
        if (!empty($data['complaint']['kind'])) {
            $notice = $data['complaint'];
            $text = __('Reported as spam by the recipient');
        } elseif (!empty($data['delivered']['kind'])) {
            $notice = $data['delivered'];
            $text = __('Delivered');
        } elseif (!empty($data['delivery_delayed']['kind'])) {
            $notice = $data['delivery_delayed'];
            $text = __('Delivery delayed');
        } else {
            return null;
        }

        $details = array_filter([
            e($notice['reporter'] ?? ''),
            e(\App\User::dateFormat($notice['at'] ?? null, 'M j, Y')),
        ]);
        if (!empty($notice['thread_id']) && !empty($notice['conversation_id']) && ($conversation = \App\Conversation::find($notice['conversation_id']))) {
            $details[] = '<a href="'.route('conversations.view', ['id' => $conversation->id]).'#thread-'.$notice['thread_id'].'">#'.$conversation->number.'</a>';
        }

        return ['kind' => $notice['kind'], 'text' => $text, 'details' => implode(', ', $details)];
    }

    /**
     * The flagged ones among these addresses: [address => problem].
     */
    public static function flagged(array $addresses)
    {
        $addresses = array_values(array_filter(array_map(function ($address) {
            return Email::sanitizeEmail(trim((string) $address));
        }, $addresses)));
        if (!$addresses) {
            return [];
        }

        return Email::whereIn('email', $addresses)->whereNotNull('delivery_problem')->get()
            ->filter(fn ($email) => !empty($email->delivery_problem['kind']))
            ->mapWithKeys(fn ($email) => [$email->email => $email->delivery_problem])
            ->all();
    }

    /**
     * "Bounced Oct 8, 2026 (Complaint)".
     */
    public static function flagText(array $problem)
    {
        return __('Bounced :date (:kind)', [
            'date' => \App\User::dateFormat($problem['at'] ?? null, 'M j, Y'),
            'kind' => self::kindLabel($problem['kind'] ?? ''),
        ]);
    }

    /**
     * The composer's warning for a flagged recipient.
     */
    public static function warningText($address, array $problem)
    {
        return __('An email to :email failed on :date: :reason', [
            'email'  => $address,
            'date'   => \App\User::dateFormat($problem['at'] ?? null, 'M j, Y'),
            'reason' => self::reasonText($problem['reason'] ?? ''),
        ]);
    }
}

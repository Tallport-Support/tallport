<?php

namespace App\Misc;

use App\Email;
use App\Http\Controllers\AttachmentsController;
use App\Incoming\DeliveryReport;
use App\Thread;

/**
 * Delivery reports (bounces, delays, complaints, suppression notices) in the
 * conversation: what a report thread says (send_status_data's delivery_report,
 * App\Incoming\DeliveryReport::toArray()), and the addresses emails couldn't
 * reach (emails.delivery_problem), which the customer's profile shows and the
 * composer warns about.
 */
class DeliveryReports
{
    /**
     * A thread's delivery report, or null.
     */
    public static function forThread(Thread $thread)
    {
        $report = $thread->getSendStatusData()['delivery_report'] ?? null;

        return is_array($report) && !empty($report['kind']) && !empty($report['recipients']) ? $report : null;
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
            default:
                return __('The receiving server rejected the email.');
        }
    }

    /**
     * Mark the report's addresses as not reached. A delay isn't: the email may still arrive.
     */
    public static function flag(array $report, Thread $thread)
    {
        if ($report['kind'] == DeliveryReport::DELAYED) {
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

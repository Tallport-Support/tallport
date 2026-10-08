<?php
/**
 * Outgoing emails.
 */

namespace App;

use Illuminate\Database\Eloquent\Model;

class SendLog extends Model
{
    /**
     * Status of the email sent to the customer or user.
     * https://documentation.mailgun.com/en/latest/api-events.html#event-types.
     */
    const STATUS_ACCEPTED = 1; // accepted (for delivery)
    const STATUS_SEND_INTERMEDIATE_ERROR = 10;
    const STATUS_SEND_ERROR = 2;
    const STATUS_DELIVERY_SUCCESS = 4;
    const STATUS_DELIVERY_ERROR = 5; // rejected, failed
    const STATUS_OPENED = 6;
    const STATUS_CLICKED = 7;
    const STATUS_UNSUBSCRIBED = 8;
    const STATUS_COMPLAINED = 9;

    /**
     * Status determining successfull sending.
     *
     * @var [type]
     */
    public static $sent_success = [
        self::STATUS_ACCEPTED,
        self::STATUS_DELIVERY_SUCCESS,
        self::STATUS_OPENED,
        self::STATUS_CLICKED,
        self::STATUS_UNSUBSCRIBED,
        self::STATUS_COMPLAINED,
    ];

    /**
     * Error statuses.
     *
     * @var [type]
     */
    public static $status_errors = [
        self::STATUS_SEND_INTERMEDIATE_ERROR,
        self::STATUS_SEND_ERROR,
        self::STATUS_DELIVERY_ERROR,
    ];

    /**
     * Mail types.
     */
    const MAIL_TYPE_EMAIL_TO_CUSTOMER = 1;
    const MAIL_TYPE_USER_NOTIFICATION = 2;
    const MAIL_TYPE_AUTO_REPLY = 3;
    const MAIL_TYPE_INVITE = 4;
    const MAIL_TYPE_PASSWORD_CHANGED = 5;
    const MAIL_TYPE_WRONG_USER_EMAIL_MESSAGE = 6;
    const MAIL_TYPE_TEST = 7;
    const MAIL_TYPE_ALERT = 8;

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Customer.
     */
    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    /**
     * User.
     */
    public function user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * Thread.
     */
    public function thread()
    {
        return $this->belongsTo('App\Thread');
    }

    /**
     * Save log record.
     */
    public static function log($thread_id, $message_id, $email, $mail_type, $status, $customer_id = null, $user_id = null, $status_message = null, $smtp_queue_id = null, $provider_message_id = null)
    {
        // Sanitize status message - remove SMTP username and password.
        $status_message = \MailHelper::sanitizeSmtpStatusMessage($status_message);

        $send_log = new self();
        $send_log->thread_id = $thread_id;
        $send_log->message_id = $message_id;
        $send_log->email = $email;
        $send_log->mail_type = $mail_type;
        $send_log->status = $status;
        $send_log->customer_id = $customer_id;
        $send_log->user_id = $user_id;
        $send_log->status_message = $status_message;
        if ($smtp_queue_id) {
            $send_log->smtp_queue_id = $smtp_queue_id;
        }
        if ($provider_message_id) {
            $send_log->provider_message_id = mb_substr($provider_message_id, 0, 191);
        }
        try {
            $send_log->save();
        } catch (\Exception $e) {
            \Helper::logException($e, 'Error occurred saving a record to `send_logs` table. ');
            return false;
        }

        return true;
    }

    /**
     * The sent reply or auto reply a sending service's ID refers to: a
     * Message-ID a reply or delivery report names (SES and Postmark put
     * theirs before the @, with a domain of their own), or the ID their
     * webhooks send. Null if none.
     */
    public static function threadForProviderMessageId($message_id)
    {
        $message_id = trim((string) $message_id, " <>\t");
        if ($message_id === '') {
            return null;
        }
        $ids = [$message_id];
        $local = strstr($message_id, '@', true);
        // Only an ID long enough not to be anyone else's.
        if ($local !== false && strlen($local) >= 20) {
            $ids[] = $local;
        }
        $send_log = self::whereIn('provider_message_id', $ids)
            ->whereIn('mail_type', [self::MAIL_TYPE_EMAIL_TO_CUSTOMER, self::MAIL_TYPE_AUTO_REPLY])
            ->whereNotNull('thread_id')
            ->orderBy('id', 'desc')
            ->first();

        return $send_log ? $send_log->thread : null;
    }

    /**
     * Get name of the status.
     */
    /**
     * The status in plain words for the Logs page, its technical message kept for Details:
     * [status, technical details or ''].
     */
    public function getStatusForPeople()
    {
        $message = (string) $this->status_message;
        // tallport:check-outgoing found a reply nothing was going to send.
        if (str_starts_with($message, 'Not sent: no job is sending this reply')) {
            return [__('Not sent: the reply was never queued.'), $this->getStatusName().'. '.$message];
        }
        $status = $this->getStatusName().($message !== '' ? '. '.$message : '');
        $details = array_filter([
            $this->status == self::STATUS_SEND_ERROR && $message !== '' ? 'Message-ID: '.$this->message_id : '',
            $this->smtp_queue_id ? 'SMTP ID: '.$this->smtp_queue_id : '',
            $this->provider_message_id ? 'Provider ID: '.$this->provider_message_id : '',
        ]);

        return [$status, implode('. ', $details)];
    }

    public function getStatusName()
    {
        switch ($this->status) {
            case self::STATUS_ACCEPTED:
                return __('Accepted for delivery');
            case self::STATUS_SEND_ERROR:
                return __('Send error');
            case self::STATUS_DELIVERY_SUCCESS:
                return __('Successfully delivered');
            case self::STATUS_DELIVERY_ERROR:
                return __('Delivery error');
            case self::STATUS_OPENED:
                return __('Recipient opened the message');
            case self::STATUS_CLICKED:
                return __('Recipient clicked a link in the message');
            case self::STATUS_UNSUBSCRIBED:
                return __('Recipient unsubscribed');
            case self::STATUS_COMPLAINED:
                return __('Recipient complained');
            default:
                return __('Unknown');
        }
    }

    public function isErrorStatus()
    {
        if (in_array($this->status, self::$status_errors)) {
            return true;
        } else {
            return false;
        }
    }

    public function isSuccessStatus()
    {
        if (in_array($this->status, [self::STATUS_DELIVERY_SUCCESS])) {
            return true;
        } else {
            return false;
        }
    }

    public function getMailTypeName()
    {
        switch ($this->mail_type) {
            case self::MAIL_TYPE_EMAIL_TO_CUSTOMER:
                return __('Email to customer');
            case self::MAIL_TYPE_USER_NOTIFICATION:
                return __('User notification');
            case self::MAIL_TYPE_AUTO_REPLY:
                return __('Auto reply to customer');
            case self::MAIL_TYPE_INVITE:
                return __('User invite');
            case self::MAIL_TYPE_PASSWORD_CHANGED:
                return __('Password changed notification');
            case self::MAIL_TYPE_WRONG_USER_EMAIL_MESSAGE:
                return __('User replied from wrong email address');
            case self::MAIL_TYPE_TEST:
                return __('Test email');
            case self::MAIL_TYPE_ALERT:
                return __('Alert email');
        }
    }
}

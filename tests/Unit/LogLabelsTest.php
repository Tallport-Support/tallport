<?php

namespace Tests\Unit;

use App\ActivityLog;
use App\SendLog;
use Tests\TestCase;

/**
 * The words the Logs pages show for activity and for email sending.
 */
class LogLabelsTest extends TestCase
{
    public static function activityDescriptions()
    {
        return [
            [ActivityLog::DESCRIPTION_USER_LOGIN, 'Logged in'],
            [ActivityLog::DESCRIPTION_USER_LOGOUT, 'Logged out'],
            [ActivityLog::DESCRIPTION_USER_REGISTER, 'Registered'],
            [ActivityLog::DESCRIPTION_USER_LOCKED, 'Locked out'],
            [ActivityLog::DESCRIPTION_USER_LOGIN_FAILED, 'Failed login'],
            [ActivityLog::DESCRIPTION_USER_PASSWORD_RESET, 'Reset password'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER, 'Error sending email to customer'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_USER, 'Error sending email to user'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_INVITE, 'Error sending invitation email to user'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_PASSWORD_CHANGED, 'Error sending password changed notification to user'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_ALERT, 'Error sending alert'],
            [ActivityLog::DESCRIPTION_EMAILS_SENDING_WRONG_EMAIL, 'Error sending email to the user who replied to notification from wrong email'],
            [ActivityLog::DESCRIPTION_EMAILS_FETCHING_ERROR, 'Error fetching email'],
            [ActivityLog::DESCRIPTION_SYSTEM_ERROR, 'System error'],
            [ActivityLog::DESCRIPTION_USER_DELETED, 'Deleted user'],
            // A module's own description is shown as it is.
            ['module_event', 'module_event'],
        ];
    }

    /**
     * Each kind of activity in words.
     *
     * @dataProvider activityDescriptions
     */
    public function testActivityDescriptions($description, $expected)
    {
        $activity = new ActivityLog();
        $activity->description = $description;

        $this->assertSame($expected, $activity->getEventDescription());
    }

    public static function sendStatuses()
    {
        return [
            [SendLog::STATUS_ACCEPTED, 'Accepted for delivery'],
            [SendLog::STATUS_SEND_ERROR, 'Send error'],
            [SendLog::STATUS_DELIVERY_SUCCESS, 'Successfully delivered'],
            [SendLog::STATUS_DELIVERY_ERROR, 'Delivery error'],
            [SendLog::STATUS_OPENED, 'Recipient opened the message'],
            [SendLog::STATUS_CLICKED, 'Recipient clicked a link in the message'],
            [SendLog::STATUS_UNSUBSCRIBED, 'Recipient unsubscribed'],
            [SendLog::STATUS_COMPLAINED, 'Recipient complained'],
            [SendLog::STATUS_SEND_INTERMEDIATE_ERROR, 'Unknown'],
        ];
    }

    /**
     * Each sending status in words.
     *
     * @dataProvider sendStatuses
     */
    public function testSendStatuses($status, $expected)
    {
        $log = new SendLog();
        $log->status = $status;

        $this->assertSame($expected, $log->getStatusName());
    }

    public function testErrorAndSuccessStatuses()
    {
        $log = new SendLog();
        $log->status = SendLog::STATUS_DELIVERY_SUCCESS;
        $this->assertTrue($log->isSuccessStatus());
        $this->assertFalse($log->isErrorStatus());

        $log->status = SendLog::STATUS_DELIVERY_ERROR;
        $this->assertFalse($log->isSuccessStatus());
        $this->assertTrue($log->isErrorStatus());
    }

    public static function mailTypes()
    {
        return [
            [SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, 'Email to customer'],
            [SendLog::MAIL_TYPE_USER_NOTIFICATION, 'User notification'],
            [SendLog::MAIL_TYPE_AUTO_REPLY, 'Auto reply to customer'],
            [SendLog::MAIL_TYPE_INVITE, 'User invite'],
            [SendLog::MAIL_TYPE_PASSWORD_CHANGED, 'Password changed notification'],
            [SendLog::MAIL_TYPE_WRONG_USER_EMAIL_MESSAGE, 'User replied from wrong email address'],
            [SendLog::MAIL_TYPE_TEST, 'Test email'],
            [SendLog::MAIL_TYPE_ALERT, 'Alert email'],
        ];
    }

    /**
     * Each kind of email in words.
     *
     * @dataProvider mailTypes
     */
    public function testMailTypes($mail_type, $expected)
    {
        $log = new SendLog();
        $log->mail_type = $mail_type;

        $this->assertSame($expected, $log->getMailTypeName());
    }
}

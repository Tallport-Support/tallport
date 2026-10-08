<?php

namespace App\Misc;

use App\Mailbox;
use App\Option;
use App\SendLog;
//use Webklex\IMAP\Client;

// todo: rename into MailHelper
class Mail
{
    /**
     * Reply separators.
     */
    const REPLY_SEPARATOR_HTML = 'fsReplyAbove';
    const REPLY_SEPARATOR_TEXT = '-- Please reply above this line --';
    const REPLY_SEPARATOR_NOTIFICATION = 'fsNotifReplyAbove';

    /**
     * Message-ID prefixes for outgoing emails.
     * Message-IDs should not match Spam Assasin patters.
     * https://github.com/freescout-help-desk/freescout/issues/5245
     */
    const MESSAGE_ID_PREFIX_NOTIFICATION = 'TP_notify';
    const MESSAGE_ID_PREFIX_NOTIFICATION_IN_REPLY = 'TP_conversation';
    const MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER = 'TP_reply';
    const MESSAGE_ID_PREFIX_AUTO_REPLY = 'TP_autoreply';

    /**
     * The prefix emails sent before Tallport used: replies to them are still recognised.
     */
    const LEGACY_MESSAGE_ID_PREFIX = 'FS_';

    public static $all_message_id_prefixes = [
        self::MESSAGE_ID_PREFIX_NOTIFICATION,
        self::MESSAGE_ID_PREFIX_NOTIFICATION_IN_REPLY,
        self::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER,
        self::MESSAGE_ID_PREFIX_AUTO_REPLY,
        'FS_notify',
        'FS_conversation',
        'FS_reply',
        'FS_autoreply',
    ];

    /**
     * Mail drivers.
     */
    const MAIL_DRIVER_MAIL = 'mail';
    const MAIL_DRIVER_SENDMAIL = 'sendmail';
    const MAIL_DRIVER_SMTP = 'smtp';

    /**
     * Encryptions.
     */
    const MAIL_ENCRYPTION_NONE = '';
    const MAIL_ENCRYPTION_SSL = 'ssl';
    const MAIL_ENCRYPTION_TLS = 'tls';

    const FETCH_SCHEDULE_EVERY_MINUTE = 1;
    const FETCH_SCHEDULE_EVERY_TWO_MINUTES = 2;
    const FETCH_SCHEDULE_EVERY_THREE_MINUTES = 3;
    const FETCH_SCHEDULE_EVERY_FIVE_MINUTES = 5;
    const FETCH_SCHEDULE_EVERY_TEN_MINUTES = 10;
    const FETCH_SCHEDULE_EVERY_FIFTEEN_MINUTES = 15;
    const FETCH_SCHEDULE_EVERY_THIRTY_MINUTES = 30;
    const FETCH_SCHEDULE_HOURLY = 60;

    const OAUTH_PROVIDER_MICROSOFT = 'ms';
    const OAUTH_MICROSOFT_SMTP = 'smtp.office365.com';

    // Google Workspace
    const OAUTH_PROVIDER_GOOGLE = 'gw';
    const OAUTH_GOOGLE_SMTP = 'smtp.gmail.com';

    /**
     * Number of messages to fetch the headers of at a time
     * when scanning a folder in findMessageByHeaders().
     */
    const SCAN_CHUNK_SIZE = 50;

    /**
     * If reply is not extracted properly from the incoming email, add here a new separator.
     * Order is not important.
     * Idially separators must contain < or > to avoid false positives.
     * Regex separators has "regex:" in the beginning.
     */
    public static $alternative_reply_separators = [
        // https://github.com/freescout-help-desk/freescout/issues/5504
        'regex:/<\s*div[^>]+'.self::REPLY_SEPARATOR_HTML.'[^>]+>/',
        self::REPLY_SEPARATOR_HTML, // Our HTML separator
        self::REPLY_SEPARATOR_TEXT, // Our plain text separator

        // Email service providers specific separators.
        // <div class="gmail_quote" style="font-family:sans-serif;">
        '<div class="gmail_quote">', // Gmail
        '<div class="gmail_quote" ', // Gmail
        '<div class="gmail_quote gmail_quote_container"', // Gmail
        '<div class="protonmail_quote">', // https://github.com/freescout-help-desk/freescout/issues/4537
        '<div id="appendonsend"></div>', // Outlook / Live / Hotmail / Microsoft
        '<div name="quote" ',
        'yahoo_quoted_', // Yahoo, full: <div id=3D"ydp6h4f5c59yahoo_quoted_2937493705"
        '------------------ 原始邮件 ------------------', // QQ
        '------------------ Original ------------------', // QQ English
        // NetEase/Coremail (163.com, 126.com, yeah.net): Mail Master's quote container, its
        // plain-text heading, and the webmail's "在 <date>，<name> 写道：" line before its quote.
        'regex:/<div[^>]+class="[^"]*\bntes-mailmaster-quote\b/',
        '---- 回复的原邮件 ----',
        '---- Replied Message ----',
        'regex:/<p>\s*在[^<]{1,300}写道：\s*<\/p>\s*<blockquote id="isReplyContent"/',
        '<blockquote id="isReplyContent"',
        '<div id=3D"divRplyFwdMsg" dir=', // Outlook
        '<div id="divRplyFwdMsg" dir=', // Outlook
        'regex:/<hr[^>]*>\s*<div[^>]+id="divRplyFwdMsg"/', // Outlook
        'regex:/<div style="border:none;border\-top:solid \#[A-Z0-9]{6} 1\.0pt;padding:3\.0pt 0in 0in 0in">[^<]*<p class="MsoNormal"><b>/', // MS Outlook
        // https://github.com/freescout-help-desk/freescout/issues/4629#issuecomment-2870297514
        'regex:/<div style="border:none;border\-top:solid \#[A-Z0-9]{6} 1\.0pt;padding:3\.0pt 0cm 0cm 0cm">[^<]*<p class="MsoNormal"><b>/', // MS Outlook

        // General separators.
        //'regex:/<blockquote((?!quote)[^>])*>/', // General sepator. Should skip Gmail's <blockquote class="gmail_quote">.
        // https://github.com/freescout-help-desk/freescout/issues/4629#issuecomment-2870299221
        '<blockquote type="cite"',
        // This is used both for quotes and replies.
        //'<blockquote class="gmail_quote"',
        // https://github.com/freescout-help-desk/freescout/issues/4629#issuecomment-2874816496
        '<div dir="auto" id="mail-editor-reference-message-container">',
        // https://github.com/freescout-help-desk/freescout/issues/4764
        '<!--html--><section>',
        'regex:/<!\-\-html\-\->\s*<section>/',
        '<!-- originalMessage -->',
        '‐‐‐‐‐‐‐ Original Message ‐‐‐‐‐‐‐',
        '--------------- Original Message ---------------',
        '-------- Αρχικό μήνυμα --------', // Greek
    ];

    /**
     * Used to substitue encoding during mail body decoding
     * via iconv() or mb_convert_encoding().
     * https://github.com/freescout-help-desk/freescout/issues/4282
     */
    public static $encoding_substitution = [
        'iso-2022-jp' => 'iso-2022-jp-ms',
        'gb2312' => 'gb18030',
    ];

    /**
     * Used when decoding mime strings.
     */
    public static $mime_encoding_substitution = [
        'iso-2022-jp' => 'iso-2022-jp-ms',
        'ks_c_5601-1987' => 'cp949',
        //'gb2312' => 'gb18030',
    ];

    /**
     * md5 of the last applied mail config.
     */
    public static $last_mail_config_hash = '';

    /**
     * Used to get SMTP queue id when sending emails to customers.
     */
    public static $smtp_queue_id_plugin_registered = false;
    
    /**
     * Used to store the last sent email message.
     */
    public static $smtp_mime_message = '';

    /**
     * Indicates that DATA command and mail content has been sent to the mail server.
     */
    public static $smtp_data_sent = false;

    /**
     * The imap extension collects parse errors (e.g. "Invalid mailbox list:
     * <>") and reports them as a notice when the request ends, which would add
     * an error page to an otherwise good response. Collect them first:
     * shutdown functions run before the extension reports.
     */
    public static function clearImapErrorsOnShutdown()
    {
        static $registered = false;

        if ($registered || !function_exists('imap_errors')) {
            return;
        }
        $registered = true;

        register_shutdown_function(function () {
            imap_errors();
            imap_alerts();
        });
    }

    /**
     * Configure mail sending parameters.
     *
     * @param App\Mailbox $mailbox
     * @param App\User $user_from
     * @param App\Conversation $conversation
     * @param App\Thread $thread The thread being sent (a workflow's sender name).
     */
    public static function setMailDriver($mailbox = null, $user_from = null, $conversation = null, $thread = null)
    {
        if ($mailbox) {
            // Configure mail driver according to Mailbox settings.
            $oauth = $mailbox->outOauthEnabled();

            // Refresh Access Token.
            if ($oauth) {
                if ((strtotime($mailbox->oauthGetParam('issued_on')) + (int)$mailbox->oauthGetParam('expires_in')) < time()) {
                    // Try to get an access token (using the authorization code grant)
                    $token_data = \MailHelper::oauthGetAccessToken($mailbox->oauthGetParam('provider'), [
                        'client_id' => $mailbox->getOutOauthClientId(),
                        'client_secret' => $mailbox->out_password,
                        'refresh_token' => $mailbox->oauthGetParam('r_token'),
                    ]);

                    if (!empty($token_data['a_token'])) {
                        // In Google Workspace new refresh token is not returned after refreshing the token.
                        if (empty($token_data['r_token'])) {
                            $token_data['r_token'] = $mailbox->oauthGetParam('r_token');
                        }
                        $mailbox->setMetaParam('oauth', $token_data, true);
                    } elseif (!empty($token_data['error'])) {
                        $error_message = 'Error occurred refreshing oAuth Access Token: '.$token_data['error'];
                        \Helper::log(\App\ActivityLog::NAME_EMAILS_SENDING, 
                            \App\ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER, [
                            'error'   => $error_message,
                            'mailbox' => $mailbox->name,
                        ]);
                        //throw new \Exception($error_message, 1);
                    }
                }
            }

            \Config::set('mail.driver', $mailbox->getMailDriverName());
            \Config::set('mail.from', $mailbox->getMailFrom($user_from, $conversation, $thread));

            // SMTP.
            if ($mailbox->out_method == Mailbox::OUT_METHOD_SMTP) {
                \Config::set('mail.host', $mailbox->out_server);
                \Config::set('mail.port', $mailbox->out_port);
                if ($oauth) {
                    \Config::set('mail.auth_mode', 'XOAUTH2');
                    \Config::set('mail.username', $mailbox->getOutOauthUsername());
                    \Config::set('mail.password', $mailbox->oauthGetParam('a_token'));
                } else {
                    \Config::set('mail.auth_mode', '');
                    if (!$mailbox->out_username) {
                        \Config::set('mail.username', null);
                        \Config::set('mail.password', null);
                    } else {
                        \Config::set('mail.username', $mailbox->out_username);
                        \Config::set('mail.password', $mailbox->out_password);
                    }
                }
                \Config::set('mail.encryption', $mailbox->getOutEncryptionName());
            }

            // A sending service's API.
            if ($mailbox->getOutProvider()) {
                \Config::set('mail.'.$mailbox->getOutProvider(), MailProviders::mailConfig($mailbox->getOutProvider(), MailProviders::mailboxSettings($mailbox)));
            }
        } else {
            // Use default settings
            \Config::set('mail.driver', \Config::get('mail.driver'));
            \Config::set('mail.from', ['address' => self::getSystemMailFrom(), 'name' => '']);
        }

        self::reapplyMailConfig();
    }

    /**
     * Reapply new mail config.
     */
    public static function reapplyMailConfig()
    {
        // Check hash to avoid recreating MailServiceProvider.
        $mail_config_hash = md5(json_encode(\Config::get('mail')));

        if (self::$last_mail_config_hash != $mail_config_hash) {
            self::$last_mail_config_hash = $mail_config_hash;
        } else {
            return false;
        }

        // Without doing this, Swift mailer uses old config values
        // if there were emails sent with previous config.
        \App::forgetInstance('mail.manager');
        \App::forgetInstance('mailer');

        (new \Illuminate\Mail\MailServiceProvider(app()))->register();
        // We have to update Mail facade manually, as it does not happen automatically
        // and previous instance of app('mail.manager') is used.
        \Mail::swap(app('mail.manager'));

        \Eventy::action('mail.reapply_mail_config');
    }

    /**
     * Set system mail driver for sending system emails to users.
     *
     * @param App\Mailbox $mailbox
     */
    public static function setSystemMailDriver()
    {
        \Config::set('mail.driver', self::getSystemMailDriver());
        \Config::set('mail.from', [
            'address' => self::getSystemMailFrom(),
            'name'    => Option::get('company_name', \Config::get('app.name')),
        ]);

        // SMTP
        if (\Config::get('mail.driver') == self::MAIL_DRIVER_SMTP) {
            \Config::set('mail.host', Option::get('mail_host'));
            \Config::set('mail.port', Option::get('mail_port'));
            if (!Option::get('mail_username')) {
                \Config::set('mail.username', null);
                \Config::set('mail.password', null);
            } else {
                \Config::set('mail.username', Option::get('mail_username'));
                \Config::set('mail.password', \Helper::decrypt(Option::get('mail_password')));
            }
            \Config::set('mail.encryption', Option::get('mail_encryption'));
        }

        // A sending service's API.
        if (MailProviders::isProvider(\Config::get('mail.driver'))) {
            \Config::set('mail.'.\Config::get('mail.driver'), MailProviders::mailConfig(\Config::get('mail.driver'), MailProviders::systemSettings()));
        }

        self::reapplyMailConfig();
    }

    /**
     * Replace mail vars in the text.
     */
    public static function replaceMailVars($text, $data = [], $escape = false, $remove_non_replaced = false)
    {
        // Available variables to insert into email in UI.
        $vars = [];

        if (!empty($data['conversation'])) {
            $vars['{%subject%}'] = $data['conversation']->subject;
            $vars['{%conversation.number%}'] = $data['conversation']->number;
            $vars['{%customer.email%}'] = $data['conversation']->customer_email;
        }
        if (!empty($data['mailbox'])) {
            $vars['{%mailbox.email%}'] = $data['mailbox']->email;
            $vars['{%mailbox.name%}'] = $data['mailbox']->name;
            // To avoid recursion.
            if (isset($data['mailbox_from_name'])) {
                $vars['{%mailbox.fromName%}'] = $data['mailbox_from_name'];
            } else {
                $vars['{%mailbox.fromName%}'] = $data['mailbox']->getMailFrom(!empty($data['user']) ? $data['user'] : null)['name'];
            }
        }
        if (!empty($data['customer'])) {
            $vars['{%customer.fullName%}'] = $data['customer']->getFullName(true);
            $vars['{%customer.firstName%}'] = $data['customer']->getFirstName(true);
            $vars['{%customer.lastName%}'] = $data['customer']->last_name;
            $vars['{%customer.company%}'] = $data['customer']->company;
        }
        if (!empty($data['user'])) {
            $vars['{%user.fullName%}'] = $data['user']->getFullName();
            $vars['{%user.firstName%}'] = $data['user']->getFirstName();
            $vars['{%user.phone%}'] = $data['user']->phone;
            $vars['{%user.email%}'] = $data['user']->email;
            $vars['{%user.jobTitle%}'] = $data['user']->job_title;
            $vars['{%user.lastName%}'] = $data['user']->last_name;
            $vars['{%user.photoUrl%}'] = $data['user']->getPhotoUrl();
        }

        $vars = \Eventy::filter('mail_vars.replace', $vars, $data);

        /**
         * Retrieves all mail var codes from the text, including fallback values.
         *
         * @link https://regex101.com/r/icWukp/1
         */
        preg_match_all(
            '#\{%(?<var>[a-zA-Z.]+)(,fallback=(?<fallback>[^}]*))?%\}#',
            $text,
            $matches
        );

        // Add fallback values to the $vars array, if present.
        foreach ($matches['var'] as $i => $var) {
            $merge_code   = "{%{$var}%}";
            $full_match   = $matches[0][$i];
            $has_fallback = false !== strpos($full_match, ',fallback=');
            $fallback_val = $has_fallback ? $matches['fallback'][$i] ?? null : null;
            $merge_val    = isset($vars[$merge_code]) ? $vars[$merge_code] : $fallback_val;

            if (null !== $merge_val || true === $remove_non_replaced) {
                $vars[$full_match] = $merge_val;
                $vars[$merge_code] = $merge_val;
            }
        }

        $vars = \Eventy::filter('mail_vars.replace_after_fallback', $vars, $data);

        if ($escape) {
            foreach ($vars as $i => $var) {
                $vars[$i] = htmlspecialchars($var ?? '');
                $vars[$i] = nl2br($vars[$i]);
            }
        } else {
            foreach ($vars as $i => $var) {
                $vars[$i] = nl2br($var ?? '');
            }
        }

        $result = strtr($text, $vars);

        // Remove non-replaced placeholders.
        if ($remove_non_replaced) {
            $result = preg_replace('#\{%[^\.%\}]+\.[^%\}]+%\}#', '', $result ?? '');
            $result = trim($result);
        }

        return $result;
    }

    /**
     * Check if text has vars in it.
     */
    public static function hasVars($text)
    {
        return preg_match('/({%|%})/', $text ?? '');
    }

    /**
     * Remove email from a list of emails.
     */
    public static function removeEmailFromArray($list, $email)
    {
        return array_diff($list, [$email]);
    }

    /**
     * From address for sending system emails.
     */
    public static function getSystemMailFrom()
    {
        $mail_from = Option::get('mail_from');
        if (!$mail_from) {
            $mail_from = 'freescout@'.\Helper::getDomain();
        }

        return $mail_from;
    }

    /**
     * Mail driver for sending system emails.
     */
    public static function getSystemMailDriver()
    {
        return Option::get('mail_driver', 'mail');
    }

    /**
     * SMTP transcript of a failed send, with the login data masked.
     */
    public static function getSmtpLog($e)
    {
        if (!$e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            return '';
        }
        // Lines sent in reply to "334" (AUTH challenges) are base64 credentials.
        return preg_replace('#(< 334[^\n]*\n> )[^\n]*#', '$1***', $e->getDebug());
    }

    /**
     * Send test email from mailbox.
     */
    public static function sendTestMail($to, $mailbox = null)
    {
        $result = [
            'status' => 'success',
            'msg' => '',
            'log' => '',
        ];
        $failed = false;

        if ($mailbox) {
            // Configure mail driver according to Mailbox settings
            \MailHelper::setMailDriver($mailbox);

            $status_message = '';
            $smtp_log = '';

            try {
                \Mail::to([$to])->send(new \App\Mail\Test($mailbox));
            } catch (\Exception $e) {
                // We come here in case SMTP server unavailable for example
                $failed = true;
                $status_message = $e->getMessage();
                $smtp_log = self::getSmtpLog($e);
            }
        } else {
            // System email
            \MailHelper::setSystemMailDriver();

            $status_message = '';
            $smtp_log = '';

            try {
                \Mail::to([['name' => '', 'email' => $to]])
                    ->send(new \App\Mail\Test());
            } catch (\Exception $e) {
                // We come here in case SMTP server unavailable for example
                $failed = true;
                $status_message = $e->getMessage();
                $smtp_log = self::getSmtpLog($e);
            }
        }

        if (\Mail::failures() || $failed) {
            SendLog::log(null, null, $to, SendLog::MAIL_TYPE_TEST, SendLog::STATUS_SEND_ERROR, null, null, $status_message);
            if ($status_message) {
                $result['msg'] = $status_message;
            }
            $result['status'] = 'error';
            $result['log'] = $smtp_log;
        } else {
            SendLog::log(null, null, $to, SendLog::MAIL_TYPE_TEST, SendLog::STATUS_ACCEPTED);

            $result['status'] = 'success';
        }

        return $result;
    }

    /**
     * Check POP3/IMAP connection to the mailbox.
     */
    public static function fetchTest($mailbox)
    {
        $result = [
            'result' => 'success',
            'msg' => '',
            'log' => '',
        ];

        $client = null;

        // The client prints its debug log.
        ob_start();

        try {
            $client = \MailHelper::getMailboxClient($mailbox, true);

            // Connect to the Server
            $client->connect();

            // Get folder
            $folder = $client->getFolder('INBOX');

            if (!$folder) {
                throw new \Exception('Could not get mailbox folder: INBOX', 1);
            }
            // Get unseen messages for a period
            $messages = $folder->query()->unseen()->since(now()->subDays(1))->leaveUnread()->get();

            $last_error = $client->getLastError();
            
            if ($last_error && stristr($last_error, 'The specified charset is not supported')) {
                // Solution for MS mailboxes.
                // https://github.com/freescout-helpdesk/freescout/issues/176
                $messages = $folder->query()->unseen()->since(now()->subDays(1))->leaveUnread()->setCharset(null)->get();
                $last_error = $client->getLastError();
            }

            if ($last_error) {
                //throw new \Exception($last_error, 1);
                $result['result'] = 'error';
                $result['msg'] = $last_error;
            }
        } catch (\Throwable $e) {
            $result['result'] = 'error';
            $result['msg'] = $e->getMessage();
        }

        // Log out while the debug output is still captured: the client would
        // print it when destroyed, into the ajax response.
        try {
            if ($client) {
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            // Not connected.
        }
        $client = null;
        $debug_output = ob_get_clean();

        if ($result['result'] == 'error') {
            $result['log'] = $debug_output;
        }

        return $result;
    }

    /**
     * Convert list of emails to array.
     *
     * @return array
     */
    public static function sanitizeEmails($emails)
    {
        $emails_array = [];

        if (is_array($emails)) {
            $emails_array = $emails;
        } else {
            $emails_array = explode(',', $emails ?? '');
        }

        foreach ($emails_array as $i => $email) {
            $emails_array[$i] = \App\Email::sanitizeEmail($email);
            if (!$emails_array[$i]) {
                unset($emails_array[$i]);
            }
        }

        return $emails_array;
    }

    /**
     * Check if email format is valid.
     *
     * @param [type] $email [description]
     *
     * @return [type] [description]
     */
    public static function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Send system alert to super admin.
     */
    public static function sendAlertMail($text, $title = '')
    {
        \App\Jobs\SendAlert::dispatch($text, $title)->onQueue('emails');
    }

    /**
     * Send email to developers team.
     */
    public static function sendEmailToDevs($subject, $body, $attachments = [], $from_user = null)
    {
        // Configure mail driver according to Mailbox settings
        \MailHelper::setSystemMailDriver();

        $status_message = '';

        try {
            \Mail::raw($body, function ($message) use ($subject, $attachments, $from_user) {
                $message
                    ->subject($subject)
                    ->to(\Config::get('app.freescout_email'));
                if ($attachments) {
                    foreach ($attachments as $attachment) {
                        $message->attach($attachment);
                    }
                }
                // Set user as Reply-To
                if ($from_user) {
                    $message->replyTo($from_user->email, $from_user->getFullName());
                }
            });
        } catch (\Exception $e) {
            \Log::error(\Helper::formatException($e));
            // We come here in case SMTP server unavailable for example
            return false;
        }

        if (\Mail::failures()) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get email marker for the outgoing email to track replies
     * in case Message-ID header is removed by mail service provider.
     *
     * @param [type] $message_id [description]
     *
     * @return [type] [description]
     */
    public static function getMessageMarker($message_id)
    {
        // It has to be BASE64, as Gmail converts it into link.
        return '{#FS:'.base64_encode($message_id).'#}';
    }

    /**
     * Fetch Message-ID from incoming email body.
     *
     * @param [type] $message_id [description]
     *
     * @return [type] [description]
     */
    public static function fetchMessageMarkerValue($body)
    {
        preg_match('/{#FS:([^#]+)#}/', $body ?? '', $matches);
        if (!empty($matches[1]) && base64_decode($matches[1])) {
            // Return first found marker.
            return base64_decode($matches[1]);
        }

        return '';
    }

    public static function getMessageIdHash($thread_id)
    {
        return substr(md5($thread_id.config('app.key')), 0, 16);
    }

    /**
     * Detect autoresponder by headers.
     * https://github.com/jpmckinney/multi_mail/wiki/Detecting-autoresponders
     * https://www.jitbit.com/maxblog/18-detecting-outlook-autoreplyout-of-office-emails-and-x-auto-response-suppress-header/.
     *
     * @return bool [description]
     */
    public static function isAutoResponder($headers_str)
    {
        $autoresponder_headers = [
            'x-autoreply'    => '',
            'x-autorespond'  => '',
            'x-autoresponder'  => '',
            'auto-submitted' => '', // this can be auto-replied, auto-generated, etc.
            'delivered-to' => ['autoresponder'],
            'precedence' => ['auto_reply', 'bulk', 'junk', 'list'],
            'x-precedence' => ['auto_reply', 'bulk', 'junk', 'list'],
        ];
        $headers = explode("\n", $headers_str ?? '');

        foreach ($autoresponder_headers as $auto_header => $auto_header_value) {
            foreach ($headers as $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) == 2) {
                    $name = trim(strtolower($parts[0]));
                    $value = trim($parts[1]);
                } else {
                    continue;
                }
                if (strtolower($name) == $auto_header) {
                    if (!$auto_header_value) {
                        return true;
                    } elseif (is_array($auto_header_value)) {
                        foreach ($auto_header_value as $auto_header_value_item) {
                            if ($value == $auto_header_value_item) {
                                return true;
                            }
                        }
                    } elseif ($value == $auto_header_value) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check Content-Type header.
     * This is not 100% reliable, detects only standard DSN bounces.
     *
     * @param [type] $headers [description]
     *
     * @return [type] [description]
     */
    public static function detectBounceByHeaders($headers)
    {
        if (preg_match("/Content-Type:((?:[^\n]|\n[\t ])+)(?:\n[^\t ]|$)/i", $headers, $match)
            && preg_match("/multipart\/report/i", $match[1])
            && preg_match("/report-type=[\"']?delivery-status[\"']?/i", $match[1])
        ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Parse email headers.
     *
     * @param [type] $headers_str [description]
     *
     * @return [type] [description]
     */
    public static function parseHeaders($headers_str)
    {
        // Raw values by name ("message_id"), as Tallport keeps headers.
        return (object) \App\Incoming\HeaderText::all($headers_str);
    }

    // Replacement for https://www.php.net/manual/en/function.imap-utf8.php
    public static function imapUtf8($mime_encoded_text)
    {
        if (function_exists('imap_utf8')) {
            return imap_utf8($mime_encoded_text);
        } else {
            return \Helper::iconvMimeDecode($mime_encoded_text);
        }
    }

    // Accepts both header name with "-" and "_".
    public static function getHeader($headers_str, $header)
    {
        $value = '';
        $headers_str = $headers_str ?? '';

        $header = strtolower($header);
        $header = str_replace('-', '_', $header);

        $header_dashed = str_replace('_', '-', $header);
        // Quick check to save resources.
        if (!stristr($headers_str, $header_dashed)) {
            return '';
        }

        // For single-line headers try to get haeder by using regular expression.
        if ($header == 'message_id') {
            if (preg_match('/^'.preg_quote($header_dashed).'\s*:((?:[^\r\n]|\r?\n[ \t])*)/im', $headers_str ?? '', $m)
                && !empty($m[1])
            ) {
                $value = $m[1];
            }
        }

        if (!$value) {
            $headers = self::parseHeaders($headers_str);
            if (!$headers) {
                return;
            }

            $value = null;
            if (property_exists($headers, $header)) {
                $value = $headers->$header;
            } else {
                return '';
            }
        }

        // Sanitize.
        switch ($header) {
            case 'message_id':
                // Message-ID can't contain spaces, so it's safe to remove them
                // along with the line folding.
                $value = str_replace(['<', '>', "\r", "\n", "\t", ' '], '', $value);
                break;
        }

        return $value;
    }

    /**
     * Get client for fetching emails.
     *
     * @param  bool  $debug  The webklex 6 client prints the IMAP conversation
     *                       (with the emails): for the connection test and --debug.
     */
    public static function getMailboxClient($mailbox, $debug = false)
    {
        $oauth = $mailbox->oauthEnabled();

        // Refresh Access Token.
        if ($oauth) {
            if ((strtotime($mailbox->oauthGetParam('issued_on')) + (int)$mailbox->oauthGetParam('expires_in')) < time()) {
                // Try to get an access token (using the authorization code grant)
                $token_data = \MailHelper::oauthGetAccessToken($mailbox->oauthGetParam('provider'), [
                    'client_id' => $mailbox->getInOauthClientId(),
                    'client_secret' => $mailbox->in_password,
                    'refresh_token' => $mailbox->oauthGetParam('r_token'),
                ]);

                if (!empty($token_data['a_token'])) {
                    // In Google Workspace new refresh token is not returned after refreshing the token.
                    if (empty($token_data['r_token'])) {
                        $token_data['r_token'] = $mailbox->oauthGetParam('r_token');
                    }
                    $mailbox->setMetaParam('oauth', $token_data, true);
                } elseif (!empty($token_data['error'])) {
                    $error_message = 'Error occurred refreshing oAuth Access Token: '.$token_data['error'];
                    \Helper::log(
                        \App\ActivityLog::NAME_EMAILS_FETCHING, 
                        \App\ActivityLog::DESCRIPTION_EMAILS_FETCHING_ERROR, [
                            'error'   => $error_message,
                            'mailbox' => $mailbox->name,
                        ]
                    );
                    throw new \Exception($error_message, 1);
                }
            }
        }

        $account = [
            'host'          => $mailbox->in_server,
            'port'          => $mailbox->in_port,
            'encryption'    => $mailbox->getInEncryptionName(),
            'validate_cert' => $mailbox->in_validate_cert,
            'username'      => $mailbox->in_username,
            'password'      => $mailbox->in_password,
            'protocol'      => $mailbox->getInProtocolName(),
        ];
        if ($oauth) {
            $account['username'] = $mailbox->getInOauthUsername();
            $account['password'] = $mailbox->oauthGetParam('a_token');
            $account['authentication'] = 'oauth';
        }

        $cm = new \Webklex\PHPIMAP\ClientManager([
            'default'  => 'default',
            'accounts' => ['default' => $account],
            'options'  => [
                'debug'         => (bool) $debug,
                'message_key'   => 'id',
                'fetch_order'   => 'asc',
                // An invalid Date header: the time of receiving.
                'fallback_date' => 'now',
                // A message webklex can't make is fetched raw instead
                // (App\Incoming\FetchedMessage), not failing the others.
                'soft_fail'     => true,
            ],
        ]);

        return new \App\Incoming\ImapClient($cm->account('default'));
    }

    /**
     * Generate artificial Message-ID.
     */
    public static function generateMessageId($email_address, $raw_body = '')
    {
        $hash = str_random(16);
        if ($raw_body) {
            $hash = md5(strval($raw_body));
        }

        return 'fs-'.$hash.'@'.preg_replace("/.*@/", '', $email_address);
    }

    public static function isGeneratedMessageId($message_id)
    {
        return preg_match("#^fs\-[a-z0-9]+@#", $message_id ?? '');
    }

    /**
     * Fetch IMAP message by Message-ID.
     */
    public static function fetchMessage($mailbox, $message_id, $message_date = null)
    {
        $no_charset = false;

        if (!$message_id) {
            return null;
        }

        try {
            $client = \MailHelper::getMailboxClient($mailbox);
            $client->connect();
        } catch (\Exception $e) {
            \Helper::logException($e, '('.$mailbox->name.') Could not fetch specific message by Message-ID:');
            return null;
        }

        $imap_folders = \Eventy::filter('mail.fetch_message.imap_folders', \App\Incoming\AfterFetch::searchFolders($mailbox), $mailbox);

        foreach ($imap_folders as $folder_name) {
            try {
                $folder = self::getImapFolder($client, $folder_name);

                if (!$folder) {
                    \Log::error('('.$mailbox->name.') Show Original - folder not found: '.$folder_name);
                    continue;
                }
                // Message-ID: <123@123.com>
                $search_message_id = addcslashes($message_id, '\"');
                $query = $folder->query()
                    //->text('<'.$message_id.'>')
                    ->whereMessageId('"<'.$search_message_id.'>"')
                    ->leaveUnread()
                    ->limit(1);

                // Limit using date to speed up the search.
                if ($message_date) {
                    $query->since($message_date->copy()->subDays(7));
                    $query->before($message_date->copy()->addDays(7));
                }

                if ($no_charset) {
                    $query->setCharset(null);
                }

                $messages = $query->get();

                $last_error = '';
                if (method_exists($client, 'getLastError')) {
                    $last_error = $client->getLastError();
                }

                if ($last_error && stristr($last_error, 'The specified charset is not supported')) {
                    // Solution for MS mailboxes.
                    // https://github.com/freescout-helpdesk/freescout/issues/176
                    //$query = $folder->query()->text('<'.$message_id.'>')->leaveUnread()->limit(1)->setCharset(null);
                    $query = $folder->query()->whereMessageId('"<'.$search_message_id.'>"')->leaveUnread()->limit(1)->setCharset(null);
                    if ($message_date) {
                        $query->since($message_date->copy()->subDays(7));
                        $query->before($message_date->copy()->addDays(7));
                    }
                    $messages = $query->get();
                    $no_charset = true;
                }

                if (count($messages)) {
                    return $messages->first();
                }

            } catch (\Exception $e) {
                \Helper::logException($e, '('.$mailbox->name.') Could not fetch specific message by Message-ID via IMAP:');
            }
        }

        return self::findMessageByHeaders($mailbox, $client, $imap_folders, $message_id, $message_date);
    }

    /**
     * Find an IMAP message by Message-ID without using IMAP search.
     *
     * Some IMAP servers don't return search results by Message-ID: imap.yandex.ru
     * for example responds with "NO [UNAVAILABLE] UID SEARCH Backend error".
     * For those we fetch the headers of the messages received around the date of
     * the message and compare Message-IDs locally.
     */
    public static function findMessageByHeaders($mailbox, $client, $imap_folders, $message_id, $message_date)
    {
        // Without a date we would have to scan the whole mailbox.
        if (!$message_date) {
            return null;
        }

        $limit = (int) \Eventy::filter('mail.fetch_message.scan_limit', 500, $mailbox);

        if ($limit <= 0) {
            return null;
        }

        $message_id = trim($message_id);

        // IMAP searches by INTERNALDATE which may differ from the date in the
        // headers, so if the message is not found on its own date, try the
        // neighbouring days too.
        $date_ranges = [
            [$message_date->copy(), $message_date->copy()->addDay()],
            [$message_date->copy()->subDay(), $message_date->copy()->addDays(2)],
        ];

        $connection = $client->getConnection();

        foreach ($imap_folders as $folder_name) {
            try {
                $folder = self::getImapFolder($client, $folder_name);

                if (!$folder) {
                    continue;
                }

                $limit_reached = false;

                foreach ($date_ranges as $date_range) {
                    $client->openFolder($folder->path);

                    $uids = array_values(self::protocolResult($connection->search([
                        'SINCE "'.$date_range[0]->format('d-M-Y').'"'
                        .' BEFORE "'.$date_range[1]->format('d-M-Y').'"'
                    ])));

                    // Calling the protocol directly here, so the server response
                    // has to be checked here as well.
                    if (!count($uids)
                        && method_exists($connection, 'getLastError')
                        && $connection->getLastError()
                    ) {
                        \Log::error('('.$mailbox->name.') Show Original - IMAP search rejected by the server'
                            .' in "'.$folder_name.'"; server response: '.$connection->getLastError());
                    }

                    if (count($uids) > $limit) {
                        $uids = array_slice($uids, 0, $limit);
                        $limit_reached = true;
                    }

                    // Fetching the headers only and in chunks, to stop as soon as
                    // the message is found. Note that fetching RFC822.HEADER does
                    // not set the \Seen flag.
                    foreach (array_chunk($uids, self::SCAN_CHUNK_SIZE) as $uids_chunk) {
                        $headers = self::protocolResult($connection->headers($uids_chunk));

                        foreach ($headers as $uid => $header) {
                            if (self::getHeader($header, 'Message-ID') != $message_id) {
                                continue;
                            }

                            return $folder->query()->leaveUnread()->getMessageByUid($uid);
                        }
                    }
                }

                if ($limit_reached) {
                    \Log::error('('.$mailbox->name.') MailHelper::findMessageByHeaders(): the number of messages to scan in "'
                        .$folder_name.'" exceeds the limit ('.$limit.'), message not found: '.$message_id);
                }
            } catch (\Exception $e) {
                \Helper::logException($e, '('.$mailbox->name.') Could not find specific message by Message-ID in headers:');
            }
        }

        return null;
    }

    /**
     * The data of an IMAP command's result: webklex 6 wraps it in a Response.
     */
    public static function protocolResult($result)
    {
        if ($result instanceof \Webklex\PHPIMAP\Connection\Protocols\Response) {
            return $result->validatedData();
        }

        return $result;
    }

    public static function oauthGetAuthorizationUrl($provider_code, $params)
    {
        $args = [];
        $url = '';

        switch ($provider_code) {

            case self::OAUTH_PROVIDER_MICROSOFT:
                // https://docs.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth
                $args = [
                    'scope' => 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send',
                    'response_type' => 'code',
                    'approval_prompt' => 'auto',
                    'redirect_uri' => route('mailboxes.oauth_callback'),
                    //'state' => // passed in $params
                ];
                $args = array_merge($args, $params);
                $url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query($args);
                break;

            case self::OAUTH_PROVIDER_GOOGLE:
                $args = [
                    'scope' => 'https://mail.google.com/',
                    'response_type' => 'code',
                    'prompt' => 'consent', // Without this there will be no refresh_token in response
                    'redirect_uri' => route('mailboxes.oauth_callback'),
                    'access_type' => 'offline', // Without this there will be no refresh_token in response
                    //'state' => // passed in $params
                ];
                $args = array_merge($args, $params);
                $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($args);
                break;
        }

        return $url;
    }

    public static function oauthGetAccessToken($provider_code, $params)
    {
        $token_data = [];
        $post_params = [];

        switch ($provider_code) {

            case self::OAUTH_PROVIDER_MICROSOFT:
                $post_params = [
                    'scope' => 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send',
                    "grant_type" => "authorization_code",
                    'redirect_uri' => route('mailboxes.oauth_callback'),
                ];

                $post_params = array_merge($post_params, $params);

                // Refreshing Access Token.
                if (!empty($post_params['refresh_token'])) {
                    $post_params['grant_type'] = 'refresh_token';
                }
                
                // $postUrl = "/common/oauth2/token";
                // $hostname = "login.microsoftonline.com";
                $full_url = "https://login.microsoftonline.com/common/oauth2/v2.0/token";

                // $headers = array(
                //     // "POST " . $postUrl . " HTTP/1.1",
                //     // "Host: login.windows.net",
                //     "Content-type: application/x-www-form-urlencoded",
                // );

                $curl = curl_init($full_url);

                curl_setopt($curl, CURLOPT_POST, true);
                //curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_params);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array("application/x-www-form-urlencoded"));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                \Helper::setCurlDefaultOptions($curl);
                curl_setopt($curl, CURLOPT_TIMEOUT, 180);

                $response = curl_exec($curl);

                if ($response) {
                    $result = json_decode($response, true);

                    // [token_type] => Bearer
                    // [scope] => IMAP.AccessAsUser.All offline_access SMTP.Send User.Read
                    // [expires_in] => 4514
                    // [ext_expires_in] => 4514
                    // [expires_on] => 1646122657
                    // [not_before] => 1646117842
                    // [resource] => 00000002-0000-0000-c000-000000000000
                    // [access_token] => dd
                    // [refresh_token] => dd
                    // [id_token] => dd
                    if (!empty($result['access_token'])) {
                        $token_data['provider'] = self::OAUTH_PROVIDER_MICROSOFT;
                        $token_data['a_token'] = $result['access_token'];
                        $token_data['r_token'] = $result['refresh_token'];
                        //$token_data['id_token'] = $result['id_token'];
                        $token_data['issued_on'] = now()->toDateTimeString();
                        $token_data['expires_in'] = $result['expires_in'];
                    } else {
                        $token_data['error'] = $response;
                    }
                } else {
                    $token_data['error'] = curl_error($curl) ?: 'Response code: '.curl_getinfo($curl, CURLINFO_HTTP_CODE);
                }
                
                if (PHP_VERSION_ID < 80000) {
                    curl_close($curl);
                }
                break;

            case self::OAUTH_PROVIDER_GOOGLE:
                $post_params = [
                    //'scope' => 'https://mail.google.com/', // Just in case
                    "grant_type" => "authorization_code",
                    'redirect_uri' => route('mailboxes.oauth_callback'),
                ];

                $post_params = array_merge($post_params, $params);

                // Refreshing Access Token.
                if (!empty($post_params['refresh_token'])) {
                    $post_params['grant_type'] = 'refresh_token';
                }
                
                $full_url = "https://oauth2.googleapis.com/token";

                $curl = curl_init($full_url);

                curl_setopt($curl, CURLOPT_POST, true);
                //curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_params);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array("application/x-www-form-urlencoded"));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                \Helper::setCurlDefaultOptions($curl);
                curl_setopt($curl, CURLOPT_TIMEOUT, 180);

                $response = curl_exec($curl);

                if ($response) {
                    $result = json_decode($response, true);
                    // {
                    //   "access_token": "...",
                    //   "refresh_token": "...",
                    //   "expires_in": 3598,
                    //   "scope": "https://mail.google.com/",
                    //   "token_type": "Bearer"
                    // }
                    if (!empty($result['access_token'])) {
                        $token_data['provider'] = self::OAUTH_PROVIDER_GOOGLE;
                        $token_data['a_token'] = $result['access_token'];
                        if ($post_params['grant_type'] != 'refresh_token') {
                            $token_data['r_token'] = $result['refresh_token'];
                        }
                        //$token_data['id_token'] = $result['id_token'];
                        $token_data['issued_on'] = now()->toDateTimeString();
                        $token_data['expires_in'] = $result['expires_in'];
                    } else {
                        $token_data['error'] = $response;
                    }
                } else {
                    $token_data['error'] = curl_error($curl) ?: 'Response code: '.curl_getinfo($curl, CURLINFO_HTTP_CODE);
                }
                if (PHP_VERSION_ID < 80000) {
                    curl_close($curl);
                }
                break;
        }

        return $token_data;
    }

    public static function oauthDisconnect($provider_code, $redirect_uri, $mailbox)
    {
        switch ($provider_code) {

            case self::OAUTH_PROVIDER_MICROSOFT:
                return redirect()->away('https://login.microsoftonline.com/common/oauth2/v2.0/logout?post_logout_redirect_uri='.urlencode($redirect_uri));

            case self::OAUTH_PROVIDER_GOOGLE:
                // This does not work.
                // $post_params = [
                //     'token' => $mailbox->oauthGetParam('a_token')
                // ];

                // $full_url = "https://oauth2.googleapis.com/revoke";
                // $curl = curl_init($full_url);

                // curl_setopt($curl, CURLOPT_POST, true);
                // curl_setopt($curl, CURLOPT_POSTFIELDS, $post_params);
                // curl_setopt($curl, CURLOPT_HTTPHEADER, array("application/x-www-form-urlencoded"));
                // curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                // \Helper::setCurlDefaultOptions($curl);
                // curl_setopt($curl, CURLOPT_TIMEOUT, 180);

                // $response = curl_exec($curl);
                // $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                // if ($http_status != 200) {
                //     \Log::error('[Google Workspace OAuth] Error revoking token on logout: HTTPS Status Code - '.$http_status.'; '.json_encode($response));
                // }
                return redirect()->away($redirect_uri);
        }
    }

    public static function prepareMailable($mailable)
    {
        $custom_headers_str = config('app.custom_mail_headers');

        if (empty($custom_headers_str)) {
            return;
        }

        $custom_headers = explode(';', $custom_headers_str);

        $mailable->withSymfonyMessage(function ($message) use ($custom_headers) {
            $headers = $message->getHeaders();

            foreach ($custom_headers as $custom_header) {
                $header_parts = explode(':', $custom_header);

                $header_name = trim($header_parts[0] ?? '');
                $header_value = trim($header_parts[1] ?? '');
                if ($header_name && $header_value) {
                    $headers->addTextHeader($header_name, $header_value);
                }
            }
            return $message;
        });
    }

    /**
     * Add headers to an outgoing message. Message-ID, In-Reply-To and
     * References are ID headers in Symfony Mime; IDs it rejects (malformed
     * IDs from other mail clients) are left out rather than failing the send.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @param array                          $new_headers
     */
    public static function setMessageHeaders($message, $new_headers)
    {
        $headers = $message->getHeaders();

        foreach ($new_headers as $name => $value) {
            if (!in_array(strtolower($name), ['message-id', 'in-reply-to', 'references'])) {
                $headers->addTextHeader($name, $value);
                continue;
            }
            if (preg_match_all('/<([^>]+)>/', (string)$value, $m)) {
                $ids = $m[1];
            } else {
                $ids = preg_split('/\s+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
            }
            $valid_ids = [];
            foreach ($ids as $id) {
                try {
                    new \Symfony\Component\Mime\Address($id);
                    $valid_ids[] = $id;
                } catch (\Exception $e) {
                    // Skip.
                }
            }
            if (!$valid_ids) {
                continue;
            }
            $headers->remove($name);
            if (strtolower($name) == 'message-id') {
                $headers->addIdHeader($name, $valid_ids[0]);
            } else {
                $headers->addIdHeader($name, $valid_ids);
            }
        }
    }

    public static function getImapFolder($client, $folder_name)
    {
        // https://github.com/freescout-helpdesk/freescout/issues/3502
        $folder_name = mb_convert_encoding($folder_name, "UTF7-IMAP", "UTF-8");

        if (method_exists($client, 'getFolderByPath')) {
            return $client->getFolderByPath($folder_name);
        } else {
            return $client->getFolder($folder_name);
        }
    }

    /**
     * This function is used to decode email subjects and attachment names in Webklex libraries.
     */
    public static function decodeSubject($subject)
    {
        // Sometimes trying to decode non-encoded strings leads
        // to loosing accents.
        // https://github.com/freescout-help-desk/freescout/issues/4506
        if (!strstr($subject, '=?')) {
            return $subject;
        }
        // Remove new lines as iconv_mime_decode() may loose a part separated by new line:
        // =?utf-8?Q?Gesch=C3=A4ftskonto?= erstellen =?utf-8?Q?f=C3=BCr?=
        //  249143
        $subject = preg_replace("/[\r\n]/", '', $subject);
        // https://github.com/freescout-helpdesk/freescout/issues/3185
        //$subject = str_ireplace('=?iso-2022-jp?', '=?iso-2022-jp-ms?', $subject);
        $subject = self::substituteMimeEncoding($subject);

        // Sometimes imap_utf8() can't decode the subject, for example:
        // =?iso-2022-jp?B?GyRCIXlCaBsoQjEzMhskQjlmISEhViUsITwlRyVzGyhCJhskQiUoJS8lOSVGJWolIiFXQGxMZ0U5JE4kPyRhJE4jURsoQiYbJEIjQSU1JW0lcyEhIVo3bjQpJSglLyU5JUYlaiUiISYlbyE8JS8hWxsoQg==?=
        // and sometimes iconv_mime_decode() can't decode the subject.
        // So we are using both.
        // 
        // We are trying iconv_mime_decode() first because imap_utf8()
        // decodes umlauts into two symbols:
        // https://github.com/freescout-helpdesk/freescout/issues/2965

        // Sometimes subject is split into parts and each part is base63 encoded.
        // And sometimes it's first encoded and after that split.
        // https://github.com/freescout-helpdesk/freescout/issues/3066      

        // Step 1. Abnormal way - text is encoded and split into parts.
  
        // Only one type of encoding should be used.
        preg_match_all("/(=\?[^\?]+\?[BQ]\?)([^\?]+)(\?=)/i", $subject, $m);
        $encodings = $m[1] ?? [];
        array_walk($encodings, function ($value) {
            $value = strtolower($value);
        });
        $one_encoding = count(array_unique($encodings)) == 1;

        if ($one_encoding) {
            // First try to join all lines and parts.
            // Keep in mind that there can be non-encoded parts also:
            // =?utf-8?Q?Gesch=C3=A4ftskonto?= erstellen =?utf-8?Q?f=C3=BCr?=
            preg_match_all("/(=\?[^\?]+\?[BQ]\?)([^\?]+)(\?=)[\r\n\t ]*/i", $subject, $m);

            $joined_parts = '';
            if (count($m[1]) > 1 && !empty($m[2]) && !preg_match("/[\r\n\t ]+[^=]/i", $subject)) {
                // Example: GyRCQGlNVTtZRTkhIT4uTlMbKEI=
                $joined_parts = $m[1][0].implode('', $m[2]).$m[3][0];

                // Base64 and URL encoded string can't contain "=" in the middle
                // https://stackoverflow.com/questions/6916805/why-does-a-base64-encoded-string-have-an-sign-at-the-end
                $has_equal_in_the_middle = preg_match("#=+([^$\? =])#", $joined_parts);

                if (!$has_equal_in_the_middle) {
                    $subject_decoded = iconv_mime_decode($joined_parts, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8");

                    if ($subject_decoded 
                        && trim($subject_decoded) != trim($joined_parts)
                        && trim($subject_decoded) != trim(rtrim($joined_parts, '='))
                        && !self::isNotYetFullyDecoded($subject_decoded)
                    ) {
                        return $subject_decoded;
                    }

                    // Try imap_utf8().
                    // =?iso-2022-jp?B?IBskQiFaSEcyPDpuQ?= =?iso-2022-jp?B?C4wTU1qIVs3Mkp2JSIlLyU3JSItahsoQg==?=
                    $subject_decoded = self::imapUtf8($joined_parts);

                    if ($subject_decoded 
                        && trim($subject_decoded) != trim($joined_parts)
                        && trim($subject_decoded) != trim(rtrim($joined_parts, '='))
                        && !self::isNotYetFullyDecoded($subject_decoded)
                    ) {
                        return $subject_decoded;
                    }
                }
            }
        }

        // Step 2. Standard way - each part is encoded separately.

        // iconv_mime_decode() can't decode:
        // =?iso-2022-jp?B?IBskQiFaSEcyPDpuQC4wTU1qIVs3Mkp2JSIlLyU3JSItahsoQg==?=
        $subject_decoded = \Helper::iconvMimeDecode($subject);

        // Sometimes iconv_mime_decode() can't decode some parts of the subject:
        // =?iso-2022-jp?B?IBskQiFaSEcyPDpuQC4wTU1qIVs3Mkp2JSIlLyU3JSItahsoQg==?=
        // =?iso-2022-jp?B?GyRCQGlNVTtZRTkhIT4uTlMbKEI=?=
        if (self::isNotYetFullyDecoded($subject_decoded)) {
            $subject_decoded = self::imapUtf8($subject);
        }

        // All previous functions could not decode text.
        // mb_decode_mimeheader() properly decodes umlauts into one unice symbol.
        // But we use mb_decode_mimeheader() as a last resort as it may garble some symbols.
        // Example: =?ISO-8859-1?Q?Vorgang 538336029: M=F6chten Sie Ihre E-Mail-Adresse =E4ndern??=
        if (self::isNotYetFullyDecoded($subject_decoded)) {
            $subject_decoded = mb_decode_mimeheader($subject);
        }

        if (!$subject_decoded) {
            $subject_decoded = $subject;
        }

        return $subject_decoded;
    }

    public static function isNotYetFullyDecoded($subject_decoded)
    {
        // https://stackoverflow.com/questions/15276191/why-does-a-diamond-with-a-questionmark-in-it-appear-in-my-html
        $invalid_utf_symbols = ['�'];

        return preg_match_all("/=\?[^\?]+\?[BQ]\?/i", $subject_decoded)
            || !mb_check_encoding($subject_decoded, 'UTF-8')
            || \Str::contains($subject_decoded, $invalid_utf_symbols);
    }

    public static function getHashedReplySeparator($message_id)
    {
        $separator = \MailHelper::REPLY_SEPARATOR_HTML;

        if ($message_id) {
            $separator .= substr(md5($message_id.config('app.key')), 0, 8);
        }

        return $separator;
    }

    // Sanitize status message - remove SMTP username and password.
    public static function sanitizeSmtpStatusMessage($status_message)
    {
        $status_message = preg_replace('#(username ")[^"]+(")#', '$1***$2', $status_message ?? '');
        $status_message = preg_replace("#(Swift_Transport_Esmtp_Auth_LoginAuthenticator\->authenticate\(Object\(Swift_SmtpTransport\), ')[^\']+(', ')[^\']+('\))#", '$1***$2***$3', $status_message ?? '');

        return $status_message;
    }

    /**
     * An email file as a webklex/php-imap 6 message (for modules; Tallport
     * reads email with App\Incoming\Parser). $mailbox is no longer needed.
     */
    public static function parseEml($content, $mailbox = null)
    {
        return \Webklex\PHPIMAP\Message::fromString(
            preg_replace("/\r?\n/", "\r\n", $content),
            \Webklex\PHPIMAP\Config::make(['options' => ['fallback_date' => 'now']])
        );
    }

    // Substitue encoding during mail body decoding.
    // https://github.com/freescout-help-desk/freescout/issues/4282
    public static function substituteEncoding($encoding)
    {
        $encoding = strtolower($encoding);

        if (!empty(self::$encoding_substitution[$encoding])) {
            return self::$encoding_substitution[$encoding];
        } else {
            return $encoding;
        }
    }

    public static function substituteMimeEncoding($string)
    {
        foreach (self::$mime_encoding_substitution as $from => $into) {
            $string = str_ireplace('=?'.$from.'?', '=?'.$into.'?', $string);
        }
        return $string;
    }

    /**
     * Remove quotes surrounding a display name in an email address.
     *
     * In `From: "Tatiana Ivanova" <ti@example.org>` the quotes are just
     * a delimiter of the quoted-string, they are not a part of the name itself:
     * https://datatracker.ietf.org/doc/html/rfc5322#section-3.2.4
     */
    public static function unquotePersonalName($name)
    {
        $name = trim((string)$name);

        if (strlen($name) < 2 || !\Str::startsWith($name, '"') || !\Str::endsWith($name, '"')) {
            return $name;
        }

        $unquoted = substr($name, 1, -1);

        // Make sure that it's a single quoted-string and not something like
        // `"Foo" bar "Baz"` - all quotes inside have to be escaped.
        if (!preg_match('/^(?:[^"\\\\]|\\\\.)*$/s', $unquoted)) {
            return $name;
        }

        // Unescape quoted-pairs: \" => " and \\ => \
        return trim(preg_replace('/\\\\(.)/s', '$1', $unquoted));
    }

    public static function isFsMessageId($message_id)
    {
        return preg_match('/^('.implode('|', self::$all_message_id_prefixes).')/i', $message_id);
    }

    // public static function oauthGetProvider($provider_code, $params)
    // {
    //     $provider = null;

    //     switch ($provider_code) {
    //         case self::OAUTH_PROVIDER_MICROSOFT:
    //             $provider = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft([
    //                 // Required
    //                 'clientId'                  => $params['client_id'],
    //                 'clientSecret'              => $params['client_secret'],
    //                 'redirectUri'               => route('mailboxes.oauth_callback'),
    //                 //https://login.microsoftonline.com/common/oauth2/authorize';
    //                 'urlAuthorize'              => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
    //                 'urlAccessToken'            => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
    //                 'urlResourceOwnerDetails'   => 'https://outlook.office.com/api/v1.0/me'
    //             ]);
    //             break;
    //     }

    //     return $provider;
    // }
}

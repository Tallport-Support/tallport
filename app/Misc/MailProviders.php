<?php

namespace App\Misc;

use App\Mailbox;
use App\Option;

/**
 * Sending services a mailbox or the system emails can send through by their
 * API (App\Misc\MailManager builds the transports): Amazon SES, Mailgun,
 * Postmark and Resend. Their settings, stored in the mailbox's meta (out_api)
 * or as options (mail_<setting>), secrets encrypted; and the IDs they give
 * sent emails.
 */
class MailProviders
{
    const SES = 'ses';
    const MAILGUN = 'mailgun';
    const POSTMARK = 'postmark';
    const RESEND = 'resend';

    const NAMES = [
        self::SES      => 'Amazon SES',
        self::MAILGUN  => 'Mailgun',
        self::POSTMARK => 'Postmark',
        self::RESEND   => 'Resend',
    ];

    /**
     * Each provider's settings for sending: name => whether it is a secret.
     */
    const SETTINGS = [
        self::SES      => ['ses_key' => false, 'ses_secret' => true, 'ses_region' => false],
        self::MAILGUN  => ['mailgun_domain' => false, 'mailgun_secret' => true, 'mailgun_region' => false],
        self::POSTMARK => ['postmark_token' => true, 'postmark_stream' => false],
        self::RESEND   => ['resend_key' => true],
    ];

    /**
     * A mailbox's settings for verifying the provider's webhooks (secrets).
     */
    const WEBHOOK_SETTINGS = [
        self::MAILGUN => 'mailgun_webhook_key',
        self::RESEND  => 'resend_webhook_secret',
    ];

    const SES_REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'sa-east-1',
        'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-central-2', 'eu-north-1', 'eu-south-1',
        'ap-south-1', 'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3',
        'af-south-1', 'me-south-1', 'il-central-1', 'us-gov-west-1',
    ];

    const MAILGUN_REGIONS = ['us' => 'US', 'eu' => 'EU'];

    /**
     * Whether a mail driver (Laravel's name) is a sending service's API.
     */
    public static function isProvider($driver)
    {
        return isset(self::NAMES[(string) $driver]);
    }

    /**
     * Validation rules for a provider's settings, with the fields' prefix
     * ("out_api." or "settings.mail_"). A secret left as it is (asterisks)
     * passes.
     */
    public static function rules($provider, $prefix)
    {
        $rules = [
            self::SES => [
                'ses_key'    => 'required|string|max:128',
                'ses_secret' => 'required|string|max:255',
                'ses_region' => 'required|in:'.implode(',', self::SES_REGIONS),
            ],
            self::MAILGUN => [
                'mailgun_domain' => 'required|string|max:255|regex:/^[a-z0-9.-]+$/i',
                'mailgun_secret' => 'required|string|max:255',
                'mailgun_region' => 'required|in:'.implode(',', array_keys(self::MAILGUN_REGIONS)),
            ],
            self::POSTMARK => [
                'postmark_token'  => 'required|string|max:255',
                'postmark_stream' => 'nullable|string|max:100|regex:/^[a-z0-9_-]+$/i',
            ],
            self::RESEND => [
                'resend_key' => 'required|string|max:255',
            ],
        ][$provider] ?? [];

        $prefixed = [];
        foreach ($rules as $name => $rule) {
            $prefixed[$prefix.$name] = $rule;
        }

        return $prefixed;
    }

    /**
     * Validation rules for the system emails' settings (Settings › Mail
     * Settings): a provider's are required when it's the method chosen.
     */
    public static function systemRules()
    {
        $rules = [];
        foreach (array_keys(self::SETTINGS) as $provider) {
            foreach (self::rules($provider, 'settings.mail_') as $name => $rule) {
                $rules[$name] = str_replace('required|', 'nullable|required_if:settings.mail_driver,'.$provider.'|', $rule);
            }
        }

        return $rules;
    }

    /**
     * The system emails' settings (options): name => ['safe_password' => true, 'encrypt' => true] for secrets.
     */
    public static function systemSettingsParams()
    {
        $params = [];
        foreach (self::SETTINGS as $settings) {
            foreach (array_keys(array_filter($settings)) as $name) {
                $params['mail_'.$name] = ['safe_password' => true, 'encrypt' => true];
            }
        }

        return $params;
    }

    /**
     * Whether a provider's settings needed for sending are all there.
     */
    public static function isConfigured($provider, array $values)
    {
        foreach (self::SETTINGS[$provider] ?? [] as $name => $secret) {
            if ($name != 'postmark_stream' && trim((string) ($values[$name] ?? '')) === '') {
                return false;
            }
        }

        return isset(self::SETTINGS[$provider]);
    }

    /**
     * The mail config (mail.<provider>) App\Misc\MailManager builds the transport from.
     */
    public static function mailConfig($provider, array $values)
    {
        switch ($provider) {
            case self::SES:
                return ['key' => $values['ses_key'] ?? '', 'secret' => $values['ses_secret'] ?? '', 'region' => $values['ses_region'] ?? 'us-east-1'];
            case self::MAILGUN:
                return ['domain' => $values['mailgun_domain'] ?? '', 'secret' => $values['mailgun_secret'] ?? '', 'region' => $values['mailgun_region'] ?? 'us'];
            case self::POSTMARK:
                return ['token' => $values['postmark_token'] ?? '', 'message_stream' => $values['postmark_stream'] ?? ''];
            case self::RESEND:
                return ['key' => $values['resend_key'] ?? ''];
        }

        return [];
    }

    /**
     * The mailbox's provider settings, secrets decrypted.
     */
    public static function mailboxSettings(Mailbox $mailbox)
    {
        $values = (array) $mailbox->getMeta('out_api', []);
        foreach (self::secretNames() as $name) {
            if (!empty($values[$name])) {
                $values[$name] = \Helper::decrypt($values[$name]);
            }
        }

        return $values;
    }

    /**
     * Store the settings sent from the mailbox's outgoing settings: a secret
     * left as it is (asterisks) is kept.
     */
    public static function saveMailboxSettings(Mailbox $mailbox, array $input)
    {
        $stored = (array) $mailbox->getMeta('out_api', []);
        $secrets = self::secretNames();
        $names = array_merge(array_merge(...array_map('array_keys', array_values(self::SETTINGS))), array_values(self::WEBHOOK_SETTINGS));
        foreach ($names as $name) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = trim((string) $input[$name]);
            if (in_array($name, $secrets)) {
                if (\Helper::isSafePassword($value)) {
                    continue;
                }
                $value = $value === '' ? '' : encrypt($value);
            }
            $stored[$name] = $value;
        }
        $mailbox->setMeta('out_api', $stored);
    }

    /**
     * The system emails' provider settings (options mail_<setting>), secrets decrypted.
     */
    public static function systemSettings()
    {
        $values = [];
        foreach (self::SETTINGS as $settings) {
            foreach ($settings as $name => $secret) {
                $value = Option::get('mail_'.$name, '');
                $values[$name] = $secret ? \Helper::decrypt($value) : $value;
            }
        }

        return $values;
    }

    /**
     * Names of all secret settings.
     */
    public static function secretNames()
    {
        $names = array_values(self::WEBHOOK_SETTINGS);
        foreach (self::SETTINGS as $settings) {
            $names = array_merge($names, array_keys(array_filter($settings)));
        }

        return $names;
    }

    /**
     * The ID the service that sent an email gave it, when it isn't the
     * email's own Message-ID: Amazon SES (by SMTP too) and Postmark replace
     * the Message-ID with one made from it; Resend has its own. Read after
     * sending with the current mail config. Null if there's none.
     *
     * Amazon SES by SMTP answers "250 Ok <id>"; the email's Message-ID is then
     * <id@email.amazonses.com> (<id@region.amazonses.com> outside us-east-1).
     *
     * @param \Illuminate\Mail\SentMessage|\Symfony\Component\Mailer\SentMessage|null $sent
     */
    public static function sentMessageId($sent)
    {
        if ($sent instanceof \Illuminate\Mail\SentMessage) {
            $sent = $sent->getSymfonySentMessage();
        }
        if (!$sent instanceof \Symfony\Component\Mailer\SentMessage) {
            return null;
        }

        $driver = config('mail.driver');
        if ($driver == \MailHelper::MAIL_DRIVER_SMTP) {
            if (!preg_match('/\.amazonaws\.com$/i', (string) config('mail.host'))) {
                return null;
            }
        } elseif (!self::isProvider($driver)) {
            return null;
        }

        $id = trim($sent->getMessageId(), " <>\t");
        $original = $sent->getOriginalMessage();
        $own_id = $original instanceof \Symfony\Component\Mime\Message && $original->getHeaders()->has('Message-ID')
            ? $original->getHeaders()->get('Message-ID')->getId()
            : '';

        return $id !== '' && $id !== $own_id ? $id : null;
    }
}

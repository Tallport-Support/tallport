<?php

namespace App\Telegram;

use App\Mailbox;

/**
 * Telegram bots as a channel: a mailbox's bot receives customers' messages
 * (conversations of the chat type), and agents' replies are sent back.
 * Settings are in the mailbox's "telegram" meta: enabled, token (encrypted),
 * auto_reply, ignore_start, webhook_secret (encrypted).
 */
class Telegram
{
    /**
     * Customer channel and conversation channel.
     */
    const CHANNEL = 11;

    const CHANNEL_NAME = 'Telegram';

    const META = 'telegram';

    const LOG = 'telegram';

    public static function settings(Mailbox $mailbox)
    {
        $settings = (array) ($mailbox->meta[self::META] ?? []);

        return [
            'enabled'      => !empty($settings['enabled']),
            'token'        => isset($settings['token']) ? (string) \Helper::decryptSoft($settings['token']) : '',
            'auto_reply'   => (string) ($settings['auto_reply'] ?? ''),
            'ignore_start' => !empty($settings['ignore_start']),
        ];
    }

    /**
     * Save settings; a token or secret is stored encrypted.
     */
    public static function saveSettings(Mailbox $mailbox, array $settings)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        foreach (['enabled', 'auto_reply', 'ignore_start'] as $name) {
            if (array_key_exists($name, $settings)) {
                $meta[$name] = $settings[$name];
            }
        }
        if (array_key_exists('token', $settings)) {
            $meta['token'] = $settings['token'] !== '' ? \Helper::encrypt($settings['token']) : '';
        }
        $mailbox->setMetaParam(self::META, $meta, true);
    }

    public static function isEnabled(Mailbox $mailbox)
    {
        $settings = self::settings($mailbox);

        return $settings['enabled'] && $settings['token'] !== '';
    }

    public static function client(Mailbox $mailbox)
    {
        return new Client(self::settings($mailbox)['token']);
    }

    public static function webhookUrl(Mailbox $mailbox)
    {
        return route('telegram.webhook', ['mailbox_id' => $mailbox->id]);
    }

    /**
     * The secret Telegram sends with every update (made once per mailbox).
     */
    public static function webhookSecret(Mailbox $mailbox)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        $secret = !empty($meta['webhook_secret']) ? (string) \Helper::decryptSoft($meta['webhook_secret']) : '';
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $meta['webhook_secret'] = \Helper::encrypt($secret);
            $mailbox->setMetaParam(self::META, $meta, true);
        }

        return $secret;
    }

    /**
     * Point the bot's webhook at this mailbox.
     */
    public static function registerWebhook(Mailbox $mailbox)
    {
        self::client($mailbox)->setWebhook(self::webhookUrl($mailbox), self::webhookSecret($mailbox));
    }

    public static function log($message, ?Mailbox $mailbox = null)
    {
        \Helper::log(self::LOG, ($mailbox ? '('.$mailbox->name.') ' : '').$message);
    }
}

<?php

namespace App\Telegram;

use App\Mailbox;

/**
 * Telegram bots as a channel: a mailbox's bot receives customers' messages
 * (conversations of the chat type), and agents' replies are sent back.
 * Settings are in the mailbox's "telegram" meta: enabled, token (encrypted),
 * ignore_start, webhook_secret (encrypted); auto_reply and auto_replies (the
 * Start text by language, before the greeting: App\AutoReply\ChannelMessages).
 */
class Telegram
{
    /**
     * Customer channel and conversation channel.
     */
    const CHANNEL = 11;

    const CHANNEL_NAME = 'Telegram';

    /**
     * The formatting a reply can have (FruitUI's editor formats): what
     * Formatter::toTelegramHtml() carries over.
     */
    const FORMATS = ['bold', 'italic', 'link', 'blockquote'];

    const META = 'telegram';

    const LOG = 'telegram';

    public static function settings(Mailbox $mailbox)
    {
        $settings = (array) ($mailbox->meta[self::META] ?? []);

        return [
            'enabled'      => !empty($settings['enabled']),
            'token'        => isset($settings['token']) ? (string) \Helper::decryptSoft($settings['token']) : '',
            'auto_reply'   => (string) ($settings['auto_reply'] ?? ''),
            'auto_replies' => array_filter((array) ($settings['auto_replies'] ?? []), 'is_string'),
            'ignore_start' => !empty($settings['ignore_start']),
        ];
    }

    /**
     * Save settings; a token or secret is stored encrypted.
     */
    public static function saveSettings(Mailbox $mailbox, array $settings)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        foreach (['enabled', 'auto_reply', 'auto_replies', 'ignore_start'] as $name) {
            if (array_key_exists($name, $settings)) {
                $meta[$name] = $settings[$name];
            }
        }
        if (array_key_exists('token', $settings)) {
            $meta['token'] = $settings['token'] !== '' ? \Helper::encrypt($settings['token']) : '';
        }
        $mailbox->setMetaParam(self::META, $meta, true);
    }

    /**
     * syncDescription() once the page has been answered: a call to Telegram for each language.
     */
    public static function syncDescriptionLater(Mailbox $mailbox)
    {
        $id = $mailbox->id;
        dispatch(function () use ($id) {
            $mailbox = Mailbox::find($id);
            if ($mailbox) {
                self::syncDescription($mailbox);
            }
        })->afterResponse();
    }

    /**
     * The bot's description, what Telegram shows in the empty chat before Start: the greeting
     * (App\AutoReply\ChannelMessages) for everyone, and in each language it has (Telegram's
     * two-letter codes); languages it no longer has lose theirs.
     */
    public static function syncDescription(Mailbox $mailbox)
    {
        if (!self::isEnabled($mailbox)) {
            return;
        }
        $messages = \App\AutoReply\ChannelMessages::settings($mailbox);
        $own = $messages['channels']['telegram'][\App\AutoReply\ChannelMessages::GREETING] ?? ['mode' => \App\AutoReply\ChannelMessages::MAILBOX];
        // Its languages: the texts', or Tallport's own (the app's).
        $texts = $own['mode'] == \App\AutoReply\ChannelMessages::OWN ? $own['texts'] : ($messages['greeting']['mode'] == \App\AutoReply\ChannelMessages::OWN ? $messages['greeting']['texts'] : []);
        $languages = $own['mode'] == \App\AutoReply\ChannelMessages::MAILBOX && $messages['greeting']['mode'] == \App\AutoReply\ChannelMessages::TALLPORT
            ? array_map(fn ($locale) => \App\Ai\Settings::fromLocale($locale), config('app.locales')) : array_filter(array_keys($texts), fn ($language) => $language !== '');
        $descriptions = ['' => \App\AutoReply\ChannelMessages::greeting($mailbox, 'telegram')];
        foreach ($languages as $language) {
            $code = strtolower(explode('-', $language)[0]);
            $descriptions[$code] = $descriptions[$code] ?? \App\AutoReply\ChannelMessages::greeting($mailbox, 'telegram', $language);
        }
        $settings = (array) ($mailbox->meta[self::META] ?? []);
        foreach (array_diff((array) ($settings['description_languages'] ?? []), array_keys($descriptions)) as $code) {
            $descriptions[$code] = '';
        }
        try {
            $client = self::client($mailbox);
            foreach ($descriptions as $code => $description) {
                $client->setMyDescription(mb_substr($description, 0, 512), (string) $code);
            }
            $settings['description_languages'] = array_values(array_filter(array_keys($descriptions), fn ($code) => $code !== '' && $descriptions[$code] !== ''));
            $mailbox->setMetaParam(self::META, $settings, true);
        } catch (TelegramException $e) {
            self::log('Bot description not set: '.$e->getMessage(), $mailbox, 'failed', 'send');
        }
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

    public static function log($message, ?Mailbox $mailbox = null, $status = 'info', $type = 'receive')
    {
        \App\Misc\ChatLog::record('telegram', $mailbox ? $mailbox->id : null, $type, $status, ($mailbox ? '('.$mailbox->name.') ' : '').$message);
    }
}

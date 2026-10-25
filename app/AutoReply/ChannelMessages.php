<?php

namespace App\AutoReply;

use App\Conversation;
use App\Mailbox;

/**
 * A mailbox's auto reply and greeting for all its channels, in languages, with a channel's own (or
 * none) where someone wants it: all in its Auto Reply settings, not on each channel's page.
 *
 * Auto reply: after the customer's first message in a new conversation, on every channel. The
 * mailbox's is its email auto reply of before (Mailbox's columns, MailboxAutoReply versions).
 * Greeting: before the customer writes, on a channel that can show one (Website Chat's window,
 * Telegram's description and Start, Matrix when the bot joins). The mailbox's: Tallport's, its own
 * or none.
 */
class ChannelMessages
{
    const AUTO_REPLY = 'auto_reply';

    const GREETING = 'greeting';

    /**
     * A channel's: the mailbox's, its own, or none.
     */
    const MAILBOX = 'mailbox';

    const OWN = 'own';

    const OFF = 'off';

    /**
     * The mailbox's greeting: Tallport's (in the customer's language), its own, or none.
     */
    const TALLPORT = 'tallport';

    const META = 'channel_messages';

    /**
     * The channels: key => label, the conversations' channel (null: email), whether it can greet
     * (show a message before the customer writes) and whether a mailbox has it. Modules add
     * theirs (auto_reply.channels).
     */
    public static function channels()
    {
        return \Eventy::filter('auto_reply.channels', [
            'email'    => ['label' => __('Email'), 'channel' => null, 'greets' => false, 'has' => fn (Mailbox $mailbox) => $mailbox->hasEmail()],
            'webchat'  => ['label' => __('Website Chat'), 'channel' => \App\WebChat\WebChat::CHANNEL, 'greets' => true, 'has' => fn (Mailbox $mailbox) => \App\WebChat\WebChat::settings($mailbox)['enabled']],
            'telegram' => ['label' => 'Telegram', 'channel' => \App\Telegram\Telegram::CHANNEL, 'greets' => true, 'has' => fn (Mailbox $mailbox) => \App\Telegram\Telegram::isEnabled($mailbox)],
            'matrix'   => ['label' => 'Matrix', 'channel' => \App\Matrix\Matrix::CHANNEL, 'greets' => true, 'has' => fn (Mailbox $mailbox) => (bool) \App\Matrix\MatrixMailbox::forMailbox($mailbox->id)],
            'nostr'    => ['label' => 'Nostr', 'channel' => \App\Nostr\Nostr::channel(), 'greets' => false, 'has' => fn (Mailbox $mailbox) => \App\Nostr\NostrMailbox::where('mailbox_id', $mailbox->id)->where('enabled', true)->whereNotNull('pubkey')->exists()],
        ]);
    }

    /**
     * The channels a mailbox has (that greet, for the greeting).
     */
    public static function forMailbox(Mailbox $mailbox, $greets = false)
    {
        return array_filter(self::channels(), fn ($channel) => (!$greets || $channel['greets']) && ($channel['has'])($mailbox));
    }

    /**
     * A conversation's channel key, or null for one without these (a module's).
     */
    public static function key(Conversation $conversation)
    {
        foreach (self::channels() as $key => $channel) {
            if ((int) $channel['channel'] === (int) $conversation->channel) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Its settings: the mailbox's greeting, and the channels' own (or none): texts by language
     * ('' the default; an auto reply's with a subject for email), one just added maybe without
     * a text yet (filled()).
     */
    public static function settings(Mailbox $mailbox)
    {
        $meta = (array) $mailbox->getMeta(self::META, []);
        $greeting = (array) ($meta['greeting'] ?? []);
        $channels = [];
        foreach ((array) ($meta['channels'] ?? []) as $key => $kinds) {
            foreach ([self::AUTO_REPLY, self::GREETING] as $kind) {
                $channels[$key][$kind] = [
                    'mode'  => in_array($kinds[$kind]['mode'] ?? null, [self::OWN, self::OFF], true) ? $kinds[$kind]['mode'] : self::MAILBOX,
                    'texts' => (array) ($kinds[$kind]['texts'] ?? []),
                ];
            }
        }

        return [
            'greeting' => [
                'mode'  => in_array($greeting['mode'] ?? null, [self::OWN, self::OFF], true) ? $greeting['mode'] : self::TALLPORT,
                'texts' => array_map('strval', (array) ($greeting['texts'] ?? [])),
            ],
            'channels' => $channels,
        ];
    }

    /**
     * The versions that have a text: those a message is chosen from.
     */
    public static function filled(array $texts)
    {
        return array_filter($texts, fn ($text) => self::message($text) !== '');
    }

    public static function saveSettings(Mailbox $mailbox, array $settings)
    {
        $mailbox->setMetaParam(self::META, ['greeting' => $settings['greeting'], 'channels' => $settings['channels']], true);
    }

    /**
     * A channel's auto reply or greeting: the mailbox's, its own or none.
     */
    public static function mode(Mailbox $mailbox, $key, $kind)
    {
        return self::settings($mailbox)['channels'][$key][$kind]['mode'] ?? self::MAILBOX;
    }

    /**
     * Whether a channel has an auto reply (which one, in which language, comes when it's sent):
     * none at all while the mailbox's auto reply is off, its switch for all channels.
     */
    public static function hasAutoReply(Mailbox $mailbox, $key)
    {
        $mode = self::mode($mailbox, $key, self::AUTO_REPLY);

        return $mailbox->auto_reply_enabled && ($mode == self::MAILBOX || ($mode == self::OWN && self::filled(self::settings($mailbox)['channels'][$key][self::AUTO_REPLY]['texts'])));
    }

    /**
     * The auto reply a new conversation gets on its channel: subject (email's), message (HTML)
     * and language (null: the default); null for none. A chat's own are plain text.
     */
    public static function autoReply(Conversation $conversation, Mailbox $mailbox)
    {
        $key = self::key($conversation);
        $mode = $key && $mailbox->auto_reply_enabled ? self::mode($mailbox, $key, self::AUTO_REPLY) : self::OFF;
        if ($mode == self::MAILBOX) {
            return trim(strip_tags((string) $mailbox->auto_reply_message)) !== '' ? AutoReplies::forConversation($conversation, $mailbox) : null;
        }
        if ($mode == self::OFF) {
            return null;
        }
        $texts = self::filled(self::settings($mailbox)['channels'][$key][self::AUTO_REPLY]['texts']);
        $languages = array_values(array_filter(array_keys($texts), fn ($language) => $language !== ''));
        $language = $languages ? AutoReplies::language($conversation, $mailbox, $languages) : null;
        $text = $texts[$language ?? ''] ?? null;
        if (!$text) {
            return null;
        }
        $message = is_array($text) ? self::message($text) : '<p>'.nl2br(e(self::message($text)), false).'</p>';

        return ['subject' => (string) (is_array($text) ? ($text['subject'] ?? '') : '') ?: $mailbox->auto_reply_subject, 'message' => $message, 'language' => $language];
    }

    /**
     * The greeting on a channel that greets (plain text, variables filled in; without the
     * customer their fallbacks), in a language (an IETF tag: a Telegram app's, the window's
     * locale's); '' for none.
     */
    public static function greeting(Mailbox $mailbox, $key, $language_tag = '', $customer = null)
    {
        $settings = self::settings($mailbox);
        $mode = $settings['channels'][$key][self::GREETING]['mode'] ?? self::MAILBOX;
        if ($mode == self::OFF) {
            return '';
        }
        if ($mode == self::OWN) {
            $texts = $settings['channels'][$key][self::GREETING]['texts'];
        } elseif ($settings['greeting']['mode'] == self::OWN) {
            $texts = $settings['greeting']['texts'];
        } elseif ($settings['greeting']['mode'] == self::TALLPORT) {
            return self::tallportGreeting($language_tag, $customer);
        } else {
            return '';
        }
        $texts = array_map(fn ($text) => self::message($text), self::filled($texts));
        $language = AutoReplies::fromLanguageTag($language_tag, array_values(array_filter(array_keys($texts), fn ($language) => $language !== '')));
        $text = $texts[$language ?? ''] ?? '';
        $first_name = $customer ? trim((string) $customer->first_name) : '';
        $data = ['mailbox' => $mailbox] + ($first_name !== '' ? ['customer' => $customer] : []);

        return trim(str_replace(['<br />', '<br/>', '<br>'], "\n", \App\Misc\Mail::replaceMailVars($text, $data, false, true)));
    }

    /**
     * Tallport's greeting, in the language the tag names where Tallport speaks it.
     */
    protected static function tallportGreeting($language_tag, $customer = null)
    {
        $locale = app()->getLocale();
        $code = \App\Ai\Settings::detectedLanguage($language_tag);
        $code = ['zh-Hans' => 'zh-CN', 'zh-Hant' => 'zh-TW', 'kk' => 'kz'][$code] ?? $code;
        if ($code && in_array($code, config('app.locales'), true)) {
            $locale = $code;
        }
        $first_name = $customer ? trim((string) $customer->first_name) : '';

        return $first_name !== '' ? __('Hi :name, how can we help?', ['name' => $first_name], $locale) : __('Hi, how can we help?', [], $locale);
    }

    /**
     * A channel's choice in words (The Mailbox's, Its Own, None), for its page's link row.
     */
    public static function modeName(Mailbox $mailbox, $key, $kind)
    {
        return [self::MAILBOX => __('The Mailbox’s'), self::OWN => __('Its Own'), self::OFF => __('None')][self::mode($mailbox, $key, $kind)];
    }

    /**
     * The mailbox's greeting in words (Tallport's, Your Own, Off), for its row in the settings.
     */
    public static function greetingName(Mailbox $mailbox)
    {
        return [self::TALLPORT => __('Tallport’s'), self::OWN => __('Your Own'), self::OFF => __('Off')][self::settings($mailbox)['greeting']['mode']];
    }

    /**
     * A text's message: a text on its own (a greeting, a chat's auto reply), or an auto reply's.
     */
    protected static function message($text)
    {
        return trim((string) (is_array($text) ? ($text['message'] ?? '') : $text));
    }
}

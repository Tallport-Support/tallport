<?php

namespace App\WebChat;

use App\Mailbox;

/**
 * A chat for websites as a channel: a website loads the script (public/t/t.js), which places the
 * chat window (/t/w, WebChatController) in a frame; visitors' messages arrive in a mailbox
 * (conversations of the chat type), and the window fetches the team's replies. Settings are in the
 * mailbox's "web_chat" meta: enabled, widget (the ID websites name), origins (the websites allowed),
 * greeting, reply_time and accent.
 */
class WebChat
{
    /**
     * Customer channel and conversation channel.
     */
    const CHANNEL = 92;

    const CHANNEL_NAME = 'Website';

    const META = 'web_chat';

    /**
     * Polling, in seconds, as the window does it: while open and active, then slower; closed (for the
     * unread count, only with a conversation).
     */
    const POLL_ACTIVE = 3;
    const POLL_IDLE = 20;
    const POLL_CLOSED = 60;

    /**
     * Attachments a visitor sends: at most this many per message, each at most this many megabytes.
     */
    const MAX_FILES = 5;
    const MAX_FILE_MB = 10;

    /**
     * Text of one message, in characters.
     */
    const MAX_LENGTH = 5000;

    public static function settings(Mailbox $mailbox)
    {
        $settings = (array) ($mailbox->meta[self::META] ?? []);

        return [
            'enabled'    => !empty($settings['enabled']),
            'widget'     => (string) ($settings['widget'] ?? ''),
            'origins'    => array_values(array_filter((array) ($settings['origins'] ?? []), 'is_string')),
            'greeting'   => (string) ($settings['greeting'] ?? ''),
            'reply_time' => (string) ($settings['reply_time'] ?? ''),
            'accent'     => in_array($settings['accent'] ?? null, \FruitUI\Fruit::ACCENTS, true) ? $settings['accent'] : 'blue',
        ];
    }

    public static function saveSettings(Mailbox $mailbox, array $settings)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        foreach (['enabled', 'widget', 'origins', 'greeting', 'reply_time', 'accent'] as $name) {
            if (array_key_exists($name, $settings)) {
                $meta[$name] = $settings[$name];
            }
        }
        $mailbox->setMetaParam(self::META, $meta, true);
    }

    /**
     * A new widget ID: what the website's snippet names, not a secret.
     */
    public static function newWidgetId()
    {
        do {
            $widget = strtolower(\Str::random(12));
        } while (self::mailbox($widget, false));

        return $widget;
    }

    /**
     * The mailbox a widget ID belongs to; only an enabled one, unless $enabled is false.
     */
    public static function mailbox($widget, $enabled = true)
    {
        $widget = (string) $widget;
        if ($widget === '') {
            return null;
        }
        foreach (Mailbox::all() as $mailbox) {
            $settings = self::settings($mailbox);
            if ($settings['widget'] === $widget && ($settings['enabled'] || !$enabled)) {
                return $mailbox;
            }
        }

        return null;
    }

    /**
     * A website's origin as the allowed websites keep it: scheme, host and a port that isn't the
     * usual one ("https://www.example.com"); null when it isn't one.
     */
    public static function origin($url)
    {
        $url = trim((string) $url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        // A host with a dot (or localhost), not a word.
        if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])
            || (!str_contains($parts['host'], '.') && strtolower($parts['host']) != 'localhost' && !str_contains($parts['host'], ':'))) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if ($port == ($scheme == 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.strtolower($parts['host']).($port ? ':'.$port : '');
    }

    /**
     * Whether the widget may be on that website: one of the allowed websites. The window's own
     * origin (opened directly, or proxied by an allowed website through its domain) always may.
     */
    public static function allows(array $settings, $origin)
    {
        $origin = self::origin($origin);

        return $origin && (in_array($origin, $settings['origins'], true) || $origin === self::origin(request()->getSchemeAndHttpHost()));
    }

    /**
     * The window's styles (s) and script (j): files under public/, joined (WebChatController::asset()).
     */
    public static function assetFiles($name)
    {
        return [
            's' => ['vendor/fruitui/fruitui.css', 'css/webchat.css'],
            'j' => ['js/webchat.js', 'vendor/fruitui/standalone.global.js'],
        ][$name] ?? null;
    }

    /**
     * A version for the window's assets' addresses: they're cached for good.
     */
    public static function assetVersion($name)
    {
        return substr(md5(implode(',', array_map(fn ($file) => $file.filemtime(public_path($file)), self::assetFiles($name)))), 0, 10);
    }

    /**
     * The team as the window shows it: the mailbox's name.
     */
    public static function teamName(Mailbox $mailbox)
    {
        return $mailbox->name;
    }
}

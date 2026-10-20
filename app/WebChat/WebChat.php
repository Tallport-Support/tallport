<?php

namespace App\WebChat;

use App\Mailbox;

/**
 * A chat for websites as a channel: a website loads the script (public/t/t.js), which places the
 * chat window (/t/w, WebChatController) in a frame; visitors' messages arrive in a mailbox
 * (conversations of the chat type), and the window fetches the team's replies. Settings are in the
 * mailbox's "web_chat" meta: enabled, widget (the ID websites name), origins (the websites allowed),
 * greeting_mode (tallport, custom or none), greeting and greetings (custom: the main text, and versions
 * by language), reply_time and accent.
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
     * The chat's first message: Tallport's (translated, by name when known), the mailbox's own, or none.
     */
    const GREETING_MODES = ['tallport', 'custom', 'none'];

    /**
     * Ready-made files for websites: proxies and signing visitors in (examples/website-chat).
     */
    const EXAMPLES_URL = 'https://github.com/Tallport-Support/tallport/tree/main/examples/website-chat';

    /**
     * Polling, in seconds, as the window does it: while open and active, then slower; closed (for the
     * unread count, only with a conversation).
     */
    const POLL_ACTIVE = 3;
    const POLL_IDLE = 20;
    const POLL_CLOSED = 60;

    /**
     * A reply the visitor hasn't seen this many seconds after it showed (and who hasn't been on the
     * website meanwhile) also goes by email, to an address that counts (App\Jobs\EmailWebChatReply).
     */
    const EMAIL_AFTER = 180;

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

        $greeting = (string) ($settings['greeting'] ?? '');

        return [
            'enabled'    => !empty($settings['enabled']),
            'widget'     => (string) ($settings['widget'] ?? ''),
            'origins'    => array_values(array_filter((array) ($settings['origins'] ?? []), 'is_string')),
            // Before there was a choice: a greeting written was the own one, none was Tallport's.
            'greeting_mode' => in_array($settings['greeting_mode'] ?? null, self::GREETING_MODES, true) ? $settings['greeting_mode'] : ($greeting !== '' ? 'custom' : 'tallport'),
            'greeting'   => $greeting,
            'greetings'  => array_filter((array) ($settings['greetings'] ?? []), 'is_string'),
            'reply_time' => (string) ($settings['reply_time'] ?? ''),
            'accent'     => in_array($settings['accent'] ?? null, \FruitUI\Fruit::ACCENTS, true) ? $settings['accent'] : 'blue',
        ];
    }

    public static function saveSettings(Mailbox $mailbox, array $settings)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        foreach (['enabled', 'widget', 'origins', 'greeting_mode', 'greeting', 'greetings', 'reply_time', 'accent'] as $name) {
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
     * Where replies to a customer can also go: their first address, read fresh (getMainEmail()'s
     * cache would keep an address added since, in this process, unknown).
     */
    public static function email($customer)
    {
        return $customer ? ($customer->emails()->orderBy('id')->value('email') ?: null) : null;
    }

    /**
     * An address that now counts for the customer (confirmed with a code, or vouched for by the
     * website): theirs; when another customer has it, they're the same person, merged into that
     * one (returned); a row left without its customer is taken over.
     */
    public static function takeEmail(\App\Customer $customer, $email)
    {
        $row = \App\Email::where('email', $email)->first();
        $owner = $row ? \App\Customer::find($row->customer_id) : null;
        if ($owner && $owner->id != $customer->id) {
            $owner->mergeWith($customer);

            return $owner;
        }
        if ($row && !$owner) {
            $row->customer_id = $customer->id;
            $row->save();
        } elseif (!$row) {
            $customer->addEmail($email);
        }

        return $customer;
    }

    /**
     * One of a widget's secrets (identity_secret: signing visitors in; proxy_secret: a website's
     * proxy for /t/): made when first asked for, and made anew to rotate it.
     */
    public static function secret(Mailbox $mailbox, $name, $rotate = false)
    {
        $meta = (array) ($mailbox->meta[self::META] ?? []);
        $secret = isset($meta[$name]) ? (string) \Helper::decryptSoft($meta[$name]) : '';
        if ($secret === '' || $rotate) {
            $secret = \Str::random(48);
            $meta[$name] = \Helper::encrypt($secret);
            $mailbox->setMetaParam(self::META, $meta, true);
        }

        return $secret;
    }

    public static function hasSecret(Mailbox $mailbox, $name)
    {
        return !empty(((array) ($mailbox->meta[self::META] ?? []))[$name]);
    }

    /**
     * The visitor's address, for the limits per IP address. Through a website's proxy (which sends
     * the widget's proxy secret in X-Tallport-Proxy) it's the one the proxy puts first in
     * X-Forwarded-For; otherwise it's the address Tallport sees (its own trusted proxies apply).
     */
    public static function clientIp(\Illuminate\Http\Request $request, Mailbox $mailbox)
    {
        $proxy_secret = (string) $request->header('X-Tallport-Proxy');
        if ($proxy_secret !== '' && self::hasSecret($mailbox, 'proxy_secret') && hash_equals(self::secret($mailbox, 'proxy_secret'), $proxy_secret)) {
            $forwarded = trim(explode(',', (string) $request->server('HTTP_X_FORWARDED_FOR'))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }

        return $request->ip();
    }

    /**
     * The greeting in the current language, to a customer (by name, when they're signed in with
     * one) or to anyone: Tallport's own, or the mailbox's (its version in that language, else the
     * main text) with its variables ({%customer.firstName,fallback=there%}, {%mailbox.name%}…).
     */
    public static function greeting(Mailbox $mailbox, $customer = null)
    {
        $settings = self::settings($mailbox);
        $first_name = $customer ? trim((string) $customer->first_name) : '';
        if ($settings['greeting_mode'] == 'none') {
            return '';
        }
        if ($settings['greeting_mode'] == 'tallport') {
            return $first_name !== '' ? __('Hi :name, how can we help?', ['name' => $first_name]) : __('Hi, how can we help?');
        }
        $language = \App\Ai\Settings::fromLocale(app()->getLocale());
        $text = trim((string) ($settings['greetings'][$language] ?? '')) ?: $settings['greeting'];
        // Without a name, the variables' fallbacks show.
        $data = ['mailbox' => $mailbox] + ($first_name !== '' ? ['customer' => $customer] : []);

        return trim(str_replace(['<br />', '<br/>', '<br>'], '', \App\Misc\Mail::replaceMailVars($text, $data, false, true)));
    }

    /**
     * Images the window shows as thumbnails and opens in the browser; anything else downloads.
     */
    const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * An attachment's image type, when both its stored type and its contents say it's one of
     * IMAGE_TYPES (never the name alone: an SVG or HTML file is always a download); else null.
     */
    public static function imageType(\App\Attachment $attachment)
    {
        if (!in_array(strtolower((string) $attachment->mime_type), self::IMAGE_TYPES, true)) {
            return null;
        }
        try {
            $handle = fopen($attachment->getLocalFilePath(), 'rb');
            $head = $handle ? (string) fread($handle, 4096) : '';
            if ($handle) {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            return null;
        }
        $type = $head !== '' ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) : null;

        return in_array($type, self::IMAGE_TYPES, true) ? $type : null;
    }

    /**
     * The team as the window shows it: the mailbox's name.
     */
    public static function teamName(Mailbox $mailbox)
    {
        return $mailbox->name;
    }
}

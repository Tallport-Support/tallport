<?php

namespace App\Misc;

use App\Thread;

/**
 * Images from other servers in customers' messages aren't loaded in the
 * conversation view (tracking pixels; the agent's address and the moment
 * of reading would be told to the sender). An agent can show them for a
 * message, or always for a customer (meta key "bei": 1 shows them).
 */
class ExternalImages
{
    const META_KEY = 'bei';

    /**
     * Bodies already checked: thread ID → [html, number blocked].
     */
    protected static $blocked = [];

    /**
     * Whether a thread's images are blocked: customers' messages, unless
     * shown for the message or the customer.
     */
    public static function appliesTo(Thread $thread)
    {
        if ($thread->type != Thread::TYPE_CUSTOMER || $thread->getMeta(self::META_KEY)) {
            return false;
        }
        $customer = $thread->customer_cached;

        return !($customer && $customer->getMeta(self::META_KEY));
    }

    /**
     * The HTML without images from other servers: [html, number blocked].
     * src, srcset, poster and background attributes become data-blocked-*;
     * url(...) in styles is emptied.
     */
    public static function block($html)
    {
        $app_host = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $count = 0;

        $html = preg_replace_callback('/<[a-z][a-z0-9]*\b[^>]*>/is', function ($tag) use ($app_host, &$count) {
            return preg_replace_callback('/(\s)(src|srcset|poster|background|style)(\s*=\s*)(["\'])(.*?)\4/is', function ($attribute) use ($app_host, &$count) {
                $name = strtolower($attribute[2]);
                $value = html_entity_decode($attribute[5], ENT_QUOTES | ENT_HTML5);
                if ($name == 'style') {
                    $style = self::blockCss($value, $app_host, $count);

                    return $style === $value ? $attribute[0] : $attribute[1].'style'.$attribute[3].$attribute[4].e($style).$attribute[4];
                }
                $urls = $name == 'srcset' ? array_map(function ($item) {
                    return preg_split('/\s+/', trim($item))[0];
                }, explode(',', $value)) : [$value];
                foreach ($urls as $url) {
                    if (self::isExternal($url, $app_host)) {
                        $count++;

                        return $attribute[1].'data-blocked-'.$name.$attribute[3].$attribute[4].$attribute[5].$attribute[4];
                    }
                }

                return $attribute[0];
            }, $tag[0]);
        }, (string) $html);

        // Unquoted: src=http://...
        $html = preg_replace_callback('/<[a-z][a-z0-9]*\b[^>]*>/is', function ($tag) use ($app_host, &$count) {
            return preg_replace_callback('/(\s)(src|poster|background)(\s*=\s*)([^\s"\'>]+)/i', function ($attribute) use ($app_host, &$count) {
                if (!self::isExternal(html_entity_decode($attribute[4]), $app_host)) {
                    return $attribute[0];
                }
                $count++;

                return $attribute[1].'data-blocked-'.strtolower($attribute[2]).$attribute[3].$attribute[4];
            }, $tag[0]);
        }, $html);

        // <style> blocks.
        $html = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($style) use ($app_host, &$count) {
            return $style[1].self::blockCss($style[2], $app_host, $count).$style[3];
        }, $html);

        return [$html, $count];
    }

    protected static function blockCss($css, $app_host, &$count)
    {
        $css = preg_replace_callback('/@import\s+[^;]+;/i', function ($import) use (&$count) {
            $count++;

            return '';
        }, $css);

        return preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/is', function ($url) use ($app_host, &$count) {
            if (self::isExternal($url[2], $app_host)) {
                $count++;

                return 'url()';
            }

            return $url[0];
        }, $css);
    }

    /**
     * On another server: a URL with another host (or //host). Inline
     * (data:, cid:) and relative URLs aren't.
     */
    public static function isExternal($url, $app_host)
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^(data|cid|blob):#i', $url)) {
            return false;
        }
        $host = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, PHP_URL_HOST);
        if ($host) {
            return strtolower($host) !== $app_host;
        }

        // No host: relative (here), unless it is a web address.
        return (bool) preg_match('#^https?:#i', $url);
    }

    /**
     * The conversation view: the body without them, and a notice above it.
     */
    public static function listen()
    {
        \Eventy::addAction('thread.before_body', function ($thread) {
            if (!self::appliesTo($thread)) {
                return;
            }
            [, $count] = self::checked($thread);
            if ($count) {
                echo view('conversations/partials/external_images_notice', ['thread' => $thread])->render();
            }
        }, 17, 1);

        \Eventy::addFilter('thread.body_output', function ($body, $thread) {
            if (!self::appliesTo($thread)) {
                return $body;
            }

            return self::checked($thread, $body)[0];
        }, 20, 2);
    }

    /**
     * A thread's original body (Show Original), blocked the same way.
     */
    public static function original(Thread $thread)
    {
        $html = $thread->getCleanBodyOriginal();

        return self::appliesTo($thread) ? self::block($html)[0] : $html;
    }

    protected static function checked(Thread $thread, $body = null)
    {
        if (!isset(self::$blocked[$thread->id]) || $body !== null) {
            self::$blocked[$thread->id] = self::block($body ?? $thread->getBodyWithFormatedLinks());
        }

        return self::$blocked[$thread->id];
    }
}

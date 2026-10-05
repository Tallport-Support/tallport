<?php

namespace App\Misc;

/**
 * Settings » Appearance: logo and login banner (each with a dark-mode version), favicon, accent colour, the name
 * in browser tabs, the footer, custom CSS, and a header, footer and CSS for
 * emails to customers. "Powered by" notices in widgets can be turned off.
 */
class Branding
{
    const DEFAULT_HEADER_COLOR = '0078d7';

    /**
     * Uploaded images (file names in storage/app/public/uploads).
     */
    const IMAGES = [
        'branding.logo'    => ['jpg', 'jpeg', 'png', 'gif', 'svg'],
        'branding.logo_dark' => ['jpg', 'jpeg', 'png', 'gif', 'svg'],
        'branding.banner'  => ['jpg', 'jpeg', 'png', 'gif', 'svg'],
        'branding.banner_dark' => ['jpg', 'jpeg', 'png', 'gif', 'svg'],
        'branding.favicon' => ['ico', 'png'],
    ];

    /**
     * Settings stored as options (Settings » Branding).
     */
    const SETTINGS = [
        'branding.logo', 'branding.logo_dark', 'branding.banner', 'branding.banner_dark', 'branding.favicon', 'branding.header_color', 'branding.title',
        'branding.footer', 'branding.css', 'branding.email_css', 'branding.email_header', 'branding.email_footer',
        'branding.widget_powered_by',
    ];

    public static function get($name, $default = '')
    {
        return \Option::get($name, $default);
    }

    public static function imageUrl($name)
    {
        $file = basename((string) self::get($name));

        return $file !== '' ? \Helper::uploadedFileUrl($file) : '';
    }

    /**
     * The header colour as #rrggbb, or '' for the standard one.
     */
    public static function headerColor()
    {
        $color = strtolower(ltrim(trim((string) self::get('branding.header_color')), '#'));

        return preg_match('/^[0-9a-f]{6}$/', $color) && $color != self::DEFAULT_HEADER_COLOR ? '#'.$color : '';
    }

    /**
     * HTML with visible text, made safe; or ''.
     */
    public static function html($name)
    {
        $html = (string) self::get($name);

        return trim(strip_tags($html, '<img>')) !== '' ? \Helper::stripDangerousTags($html) : '';
    }

    /**
     * CSS without what could run code or load other things: comments,
     * tags, expression(), javascript:, behaviors and bindings, @import,
     * non-image data: URLs. Braces are balanced.
     */
    public static function sanitizeCss($css)
    {
        $css = (string) $css;
        $css = preg_replace(['#/\*.*?\*/#s', '#<!--.*?-->#s'], '', $css);
        $css = strip_tags($css);
        $css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css);
        $css = preg_replace([
            '/expression\s*\(/i',
            '/(java|vb)script\s*:/i',
            '/-?(moz-)?binding\s*:/i',
            '/behaviou?r\s*:/i',
            '/@import/i',
            '/url\s*\(\s*["\']?\s*data:(?!image\/)/i',
            '/-o-link(-source)?\s*:/i',
        ], ' ', $css);
        $open = substr_count($css, '{');
        $close = substr_count($css, '}');
        if ($close > $open) {
            $css = preg_replace('/\}/', '', $css, $close - $open);
        } elseif ($open > $close) {
            $css .= str_repeat('}', $open - $close);
        }

        return trim($css);
    }

    /**
     * Save an uploaded image (or remove it); returns the file name to keep.
     */
    public static function saveImage($name, $file, $remove)
    {
        $current = basename((string) self::get($name));
        if (($remove || $file) && $current !== '') {
            \Helper::uploadedFileRemove($current);
            $current = '';
        }
        if ($file) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, self::IMAGES[$name])) {
                throw new \Exception(__('Unsupported file type'));
            }
            $current = basename((string) \Helper::uploadFile($file, self::IMAGES[$name]));
        }

        return $current;
    }

    public static function listen()
    {
        \Eventy::addFilter('layout.header_logo', function ($url) {
            return self::imageUrl('branding.logo') ?: $url;
        }, 20, 1);
        \Eventy::addFilter('login.banner', function ($url) {
            return self::imageUrl('branding.banner') ?: $url;
        }, 20, 1);
        // The dark-mode versions; without one, the light one shows in both.
        \Eventy::addFilter('layout.header_logo_dark', function ($url) {
            return self::imageUrl('branding.logo') ? (self::imageUrl('branding.logo_dark') ?: $url) : $url;
        }, 20, 1);
        \Eventy::addFilter('login.banner_dark', function ($url) {
            return self::imageUrl('branding.banner') ? (self::imageUrl('branding.banner_dark') ?: $url) : $url;
        }, 20, 1);
        \Eventy::addFilter('layout.favicon', function ($url) {
            return self::imageUrl('branding.favicon') ?: $url;
        }, 20, 1);
        \Eventy::addFilter('layout.title.name', function ($name) {
            return trim((string) self::get('branding.title')) ?: $name;
        }, 20, 1);
        \Eventy::addFilter('layout.theme_color', function ($color) {
            return self::headerColor() ?: $color;
        }, 20, 1);
        \Eventy::addFilter('footer.text', function ($text) {
            return self::html('branding.footer') ?: $text;
        }, 20, 1);

        // The brand colour and custom CSS, after the stylesheets they override.
        \Eventy::addAction('layout.after_stylesheets', function () {
            $css = '';
            if ($color = self::headerColor()) {
                // FruitUI's brand colour: accents in both appearances derive from it.
                $css .= '.fruit-ui{--f-tint:'.$color.';}';
            }
            $css .= self::sanitizeCss(self::get('branding.css'));
            if ($css !== '') {
                echo '<style>'.str_replace('</', '<\/', $css).'</style>';
            }
        }, 20, 0);

        // Emails to customers: replies and auto replies.
        foreach (['reply_email', 'auto_reply_email'] as $email) {
            \Eventy::addFilter($email.'.css', function ($css) {
                return trim($css."\n".self::sanitizeCss(self::get('branding.email_css')));
            }, 20, 1);
            \Eventy::addFilter($email.'.header', function ($html) {
                return self::html('branding.email_header') ?: $html;
            }, 20, 1);
            \Eventy::addFilter($email.'.footer', function ($html) {
                return self::html('branding.email_footer') ?: $html;
            }, 20, 1);
        }

        // "Powered by" in widgets (knowledge base, chat, customer portal).
        foreach (['knowledgebase.powered_by', 'chat.powered_by', 'enduserportal.powered_by'] as $filter) {
            \Eventy::addFilter($filter, function ($html) {
                return self::get('branding.widget_powered_by', true) ? $html : '';
            }, 20, 1);
        }
    }
}

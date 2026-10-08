<?php

namespace App\Misc;

/**
 * Emails' "default" colours, dropped where messages are shown so the page's own apply in
 * light and dark appearance: text set to black (or near it, or Outlook's windowtext) and
 * backgrounds set to white (or near it). Other colours (brand colours, highlights, a dark
 * banner with white text) stay as the sender made them.
 */
class EmailColors
{
    /**
     * Darkest text and lightest background still taken for the default (relative luminance).
     */
    const TEXT_MAX = 0.06;
    const BACKGROUND_MIN = 0.85;

    public static function neutral($html)
    {
        $html = (string) $html;
        if ($html === '' || (stripos($html, 'color') === false && stripos($html, 'bgcolor') === false)) {
            return $html;
        }

        // In tags only (never the text): their style="…" and <font color> / bgcolor.
        return preg_replace_callback('/<[a-z][^<>]*>/i', fn ($tag) => self::neutralTag($tag[0]), $html) ?? $html;
    }

    protected static function neutralTag($html)
    {
        // style="…": color and background(-color) declarations.
        $html = preg_replace_callback('/(\sstyle\s*=\s*)(["\'])(.*?)\2/is', function ($m) {
            $declarations = array_filter(array_map('trim', explode(';', html_entity_decode($m[3], ENT_QUOTES))), 'strlen');
            $kept = array_filter($declarations, function ($declaration) {
                [$property, $value] = array_pad(array_map('trim', explode(':', $declaration, 2)), 2, '');
                $property = strtolower($property);
                if ($property == 'color') {
                    return !self::isDefaultText($value);
                }
                if ($property == 'background-color' || ($property == 'background' && !preg_match('/url\s*\(/i', $value))) {
                    return !self::isDefaultBackground($value);
                }

                return true;
            });

            return $m[1].$m[2].htmlspecialchars(implode('; ', $kept), ENT_QUOTES).$m[2];
        }, $html) ?? $html;

        // <font color="…"> and bgcolor="…".
        $html = preg_replace_callback('/\s(color|bgcolor)\s*=\s*(["\']?)([^"\'\s>]+)\2/i', function ($m) {
            $default = strtolower($m[1]) == 'color' ? self::isDefaultText($m[3]) : self::isDefaultBackground($m[3]);

            return $default ? '' : $m[0];
        }, $html) ?? $html;

        return $html;
    }

    protected static function isDefaultText($value)
    {
        $value = strtolower(trim(str_replace('!important', '', $value)));
        if (in_array($value, ['windowtext', 'black', 'initial', 'inherit', 'currentcolor'])) {
            return true;
        }
        $luminance = self::luminance($value);

        return $luminance !== null && $luminance <= self::TEXT_MAX;
    }

    protected static function isDefaultBackground($value)
    {
        $value = strtolower(trim(str_replace('!important', '', $value)));
        if (in_array($value, ['white', 'window', 'transparent', 'none', 'initial', 'inherit'])) {
            return true;
        }
        $luminance = self::luminance($value);

        return $luminance !== null && $luminance >= self::BACKGROUND_MIN;
    }

    /**
     * Relative luminance (0 black – 1 white) of #rgb, #rrggbb or rgb()/rgba(); null otherwise.
     */
    protected static function luminance($value)
    {
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            $rgb = array_map(fn ($c) => hexdec($c.$c), str_split($m[1]));
        } elseif (preg_match('/^#([0-9a-f]{6})$/', $value, $m)) {
            $rgb = array_map('hexdec', str_split($m[1], 2));
        } elseif (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/', $value, $m)) {
            $rgb = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        $linear = array_map(function ($c) {
            $c = min(255, $c) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}

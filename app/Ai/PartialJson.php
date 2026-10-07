<?php

namespace App\Ai;

/**
 * A JSON object read while it's still being written (a streamed AI answer): what's there so
 * far, with the string being written cut where it is, and keys or values not finished yet left
 * out. {"translation": "Hallo, wie ge → ['translation' => 'Hallo, wie ge'].
 */
class PartialJson
{
    /**
     * @return array what can be read; [] before the first value
     */
    public static function decode($text)
    {
        $text = (string) $text;
        $start = strpos($text, '{');
        if ($start === false) {
            return [];
        }
        $text = substr($text, $start);
        $length = strlen($text);

        // Open objects and arrays: their closing character, and for objects what comes next
        // (key, colon, value or comma).
        $stack = [];
        // Where the text can be cut and closed: after a whole value, or an object or array opened.
        $cut = 0;
        $cut_closing = '';
        $in_string = false;
        $string_is_value = false;
        // In a string: where its unfinished escape (\ or \uXXXX) starts.
        $escape_at = null;
        $closing = function () use (&$stack) {
            return implode('', array_reverse(array_column($stack, 'close')));
        };
        $valueDone = function ($at) use (&$stack, &$cut, &$cut_closing, $closing) {
            if ($stack) {
                $stack[count($stack) - 1]['next'] = 'comma';
            }
            $cut = $at;
            $cut_closing = $closing();
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($in_string) {
                if ($char == '\\') {
                    // \uXXXX or \n and alike; unfinished only at the end of the text.
                    $escape_length = ($text[$i + 1] ?? '') == 'u' ? 6 : 2;
                    if ($i + $escape_length > $length) {
                        $escape_at = $i;
                        break;
                    }
                    $i += $escape_length - 1;
                    continue;
                }
                if ($char == '"') {
                    $in_string = false;
                    if ($string_is_value) {
                        $valueDone($i + 1);
                    } elseif ($stack) {
                        $stack[count($stack) - 1]['next'] = 'colon';
                    }
                }
                continue;
            }
            $top = $stack ? $stack[count($stack) - 1] : null;
            if ($char == '"') {
                $in_string = true;
                $string_is_value = $top && !($top['close'] == '}' && $top['next'] == 'key');
                continue;
            }
            if ($char == '{' || $char == '[') {
                $stack[] = ['close' => $char == '{' ? '}' : ']', 'next' => $char == '{' ? 'key' : 'value'];
                $cut = $i + 1;
                $cut_closing = $closing();
                continue;
            }
            if ($char == '}' || $char == ']') {
                array_pop($stack);
                $valueDone($i + 1);
                if (!$stack) {
                    break;
                }
                continue;
            }
            if ($char == ':' && $top) {
                $stack[count($stack) - 1]['next'] = 'value';
                continue;
            }
            if ($char == ',' && $top) {
                $stack[count($stack) - 1]['next'] = $top['close'] == '}' ? 'key' : 'value';
                continue;
            }
            // A number, true, false or null: whole when something follows it.
            if (preg_match('/[-0-9a-z.+]/i', $char)) {
                $end = $i;
                while ($end < $length && preg_match('/[-0-9a-z.+]/i', $text[$end])) {
                    $end++;
                }
                $literal = substr($text, $i, $end - $i);
                if ($end < $length && (is_numeric($literal) || in_array($literal, ['true', 'false', 'null']))) {
                    $valueDone($end);
                }
                $i = $end - 1;
            }
        }

        if ($in_string && $string_is_value) {
            // The string being written, up to an unfinished escape or character.
            $value = substr($text, 0, $escape_at ?? $length);
            for ($drop = 0; $drop < 3 && !mb_check_encoding($value, 'UTF-8'); $drop++) {
                $value = substr($value, 0, -1);
            }
            $json = $value.'"'.$closing();
        } else {
            $json = substr($text, 0, $cut).$cut_closing;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A whole JSON object, also in a Markdown code fence or with words around it.
     *
     * @return array|null null when there's none
     */
    public static function decodeComplete($text)
    {
        $text = trim((string) $text);
        $decoded = json_decode($text, true);
        if (!is_array($decoded) && preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
        }
        if (!is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $decoded = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

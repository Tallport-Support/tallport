<?php

namespace Tests\Support;

/**
 * The translatable strings in Tallport's code and what each supported
 * language is missing. Used by TranslationsTest.
 */
class Translations
{
    /**
     * PHP translation files every supported language must have in full.
     * (installer_messages waits for the installer's replacement.)
     */
    const GROUPS = ['auth', 'passwords', 'validation'];

    public static function root()
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Supported languages other than English (config app.locales).
     *
     * @return string[]
     */
    public static function locales()
    {
        $config = include self::root().'/config/app.php';

        return array_values(array_diff($config['locales'], ['en']));
    }

    /**
     * Literal strings passed to __(), @lang(), trans() and trans_choice() in
     * app/ and resources/views (which includes the strings for JavaScript),
     * except keys of PHP translation files like "auth.throttle".
     *
     * @return string[]
     */
    public static function codeStrings()
    {
        $strings = [];
        foreach (['app', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                preg_match_all('/(?:__|@lang|trans|trans_choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?=\s*[,)])/', file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $quoted) {
                    $string = stripcslashes(substr($quoted, 1, -1));
                    if ($string !== '' && !self::isGroupKey($string)) {
                        $strings[$string] = true;
                    }
                }
            }
        }
        $strings = array_keys($strings);
        sort($strings);

        return $strings;
    }

    /**
     * A key in a PHP translation file, like "auth.throttle".
     */
    public static function isGroupKey($string)
    {
        return (bool) preg_match('/^([a-z_]+)\.\S+$/', $string, $m)
            && is_file(self::root().'/resources/lang/en/'.$m[1].'.php');
    }

    /**
     * @return array<string, string>
     */
    public static function json($locale)
    {
        $path = self::root().'/resources/lang/'.$locale.'.json';

        return is_file($path) ? json_decode(file_get_contents($path), true) : [];
    }

    /**
     * FruitUI's JSON translations of a locale.
     *
     * @return array<string, string>
     */
    public static function fruitJson($locale)
    {
        $path = self::root().'/vendor/fruitui/fruitui/lang/'.$locale.'.json';

        return is_file($path) ? json_decode(file_get_contents($path), true) : [];
    }

    /**
     * A PHP translation file as "key.subkey" => text.
     *
     * @return array<string, string>
     */
    public static function group($locale, $group)
    {
        $path = self::root().'/resources/lang/'.$locale.'/'.$group.'.php';

        return is_file($path) ? self::flatten(include $path) : [];
    }

    public static function flatten(array $array, $prefix = '')
    {
        $flat = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * What a language is missing: ['json' => [string, ...], 'groups' => [group => [key => English]]].
     */
    public static function missing($locale)
    {
        // FruitUI translates its own strings (loaded at runtime too).
        $json = self::json($locale) + self::fruitJson($locale);
        $missing = ['json' => [], 'groups' => []];
        foreach (self::codeStrings() as $string) {
            if (!isset($json[$string]) || $json[$string] === '') {
                $missing['json'][] = $string;
            }
        }
        foreach (self::GROUPS as $group) {
            $have = self::group($locale, $group);
            foreach (self::group('en', $group) as $key => $english) {
                if (!isset($have[$key]) || $have[$key] === '') {
                    $missing['groups'][$group][$key] = $english;
                }
            }
        }

        return $missing;
    }

    /**
     * Placeholders that must survive translation: :name, %name%, {name}.
     *
     * @return string[]
     */
    public static function placeholders($text)
    {
        preg_match_all('/:[A-Za-z_]+|%[a-z_]+%|\{[a-z_]+\}/', (string) $text, $matches);
        $placeholders = array_unique($matches[0]);
        sort($placeholders);

        return $placeholders;
    }
}

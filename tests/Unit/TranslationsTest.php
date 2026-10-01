<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\Translations;

/**
 * Every supported language (config app.locales) translates every string in
 * the code and Laravel's auth, passwords and validation messages, keeping
 * placeholders like :name intact. New or changed text needs a translation
 * for every language before it can be released.
 */
class TranslationsTest extends TestCase
{
    public static function locales()
    {
        return array_combine(Translations::locales(), array_map(function ($locale) {
            return [$locale];
        }, Translations::locales()));
    }

    /**
     * @dataProvider locales
     */
    public function testLanguageIsComplete($locale)
    {
        $missing = Translations::missing($locale);
        $list = array_merge($missing['json'], ...array_map(function ($group, $keys) {
            return array_map(function ($key) use ($group) {
                return $group.'.'.$key;
            }, array_keys($keys));
        }, array_keys($missing['groups']), $missing['groups']));

        $this->assertSame([], $list, "resources/lang/$locale is missing ".count($list).' translation(s)');
    }

    /**
     * @dataProvider locales
     */
    public function testPlaceholdersAreKept($locale)
    {
        $broken = [];
        $json = Translations::json($locale);
        foreach (Translations::codeStrings() as $string) {
            if (!empty($json[$string]) && Translations::placeholders($string) !== Translations::placeholders($json[$string])) {
                $broken[] = $string.' => '.$json[$string];
            }
        }
        foreach (Translations::GROUPS as $group) {
            $english = Translations::group('en', $group);
            foreach (Translations::group($locale, $group) as $key => $text) {
                if (isset($english[$key]) && $text !== '' && Translations::placeholders($english[$key]) !== Translations::placeholders($text)) {
                    $broken[] = "$group.$key => $text";
                }
            }
        }

        $this->assertSame([], $broken, "resources/lang/$locale has translations with changed placeholders");
    }
}

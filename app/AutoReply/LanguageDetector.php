<?php

namespace App\AutoReply;

/**
 * Script-based language detector for Chinese, Japanese and Korean.
 *
 * These languages are distinguishable by the
 * Unicode scripts they are written in, which is far more reliable (and
 * cheaper) than statistical n-gram detection for this particular set:
 *
 *  - Japanese always contains kana alongside kanji. Hiragana in particular:
 *    Chinese text may contain a katakana brand name, but never hiragana.
 *  - Korean is written almost entirely in Hangul.
 *  - Chinese is written in Han characters only (no kana, no Hangul).
 *  - Anything else (Latin, Cyrillic, ...) falls back to the default language.
 *  - Simplified and Traditional Chinese are told apart by the characters
 *    that differ between them (with PHP's intl extension).
 *
 * This class has no framework dependencies so it can be unit tested on its own.
 */
class LanguageDetector
{
    const LANG_ENGLISH  = 'en';
    const LANG_CHINESE  = 'zh';
    const LANG_CHINESE_SIMPLIFIED  = 'zh-Hans';
    const LANG_CHINESE_TRADITIONAL = 'zh-Hant';
    const LANG_JAPANESE = 'ja';
    const LANG_KOREAN   = 'ko';

    /**
     * Lines matching one of these patterns start a quoted/forwarded message.
     * Everything from such a line onwards is ignored.
     */
    const QUOTE_START_PATTERNS = [
        // English / generic.
        '/^On .{3,300} wrote:?\s*$/u',
        '/^-{2,}\s*Original Message\s*-{2,}$/iu',
        '/^-{2,}\s*Forwarded message\s*-{2,}$/iu',
        '/^Begin forwarded message:?$/iu',
        '/^From:\s.+$/u',
        '/^Sent:\s.+$/u',
        // German / French (common in international support mailboxes).
        '/^Am .+ schrieb .+:$/u',
        '/^Le .+ a écrit\s?:$/u',
        // Chinese.
        '/^.*(于|於|在).+(写道|寫道)[:：]?$/u',
        '/^-{2,}\s*(原始邮件|原始郵件|转发邮件|轉寄郵件)\s*-{2,}$/u',
        '/^(发件人|寄件者|寄件人|發件人)[:：]/u',
        // Japanese.
        '/^.+さんは書きました[:：]?$/u',
        '/^.+のメッセージ[:：]$/u',
        '/^-{2,}\s*元のメッセージ\s*-{2,}$/u',
        '/^(差出人|送信元|送信者)[:：]/u',
        // Korean.
        '/^.+님이 작성[:：]?$/u',
        '/^-{2,}\s*원본 (이메일|메시지|메일)\s*-{2,}$/u',
        '/^보낸 ?사람[:：]/u',
        // Gmail date headers in CJK locales, e.g. "2024年1月1日(月) 15:00 山田太郎 <...>:"
        '/^\d{4}年\s?\d{1,2}月\s?\d{1,2}日.*[:：]$/u',
        '/^\d{4}년\s?\d{1,2}월\s?\d{1,2}일.*[:：]$/u',
    ];

    /**
     * Lines matching one of these patterns start a signature.
     * Everything from such a line onwards is ignored.
     */
    const SIGNATURE_START_PATTERNS = [
        '/^-- ?$/u',
        '/^—+$/u',
        '/^_{3,}$/u',
        '/^-{3,}$/u',
        '/^Sent from my (iPhone|iPad|Galaxy|Android|Samsung|Huawei|Xiaomi)/iu',
        '/^Get Outlook for (iOS|Android)$/iu',
        '/^(iPhone|iPad)から送信$/u',
        '/^Outlook for (iOS|Android) を入手$/u',
        '/^(내|나의) (iPhone|iPad)에서 보냄$/u',
        '/^(我的|从我的) ?(iPhone|iPad)(发送|傳送)$/u',
        '/^发自我的 ?(iPhone|iPad)$/u',
        '/^從我的 ?(iPhone|iPad)傳送$/u',
    ];

    /**
     * Detection settings (see the constructor).
     *
     * @var array
     */
    protected $options = [
        'default_language' => self::LANG_ENGLISH,
        'letters_per_word' => 5,
        'min_cjk_ratio'    => 0.5,
        'min_kana_ratio'   => 0.2,
        'katakana_only_ratio' => 0.5,
        'min_hangul_ratio' => 0.3,
        'max_length'       => 20000,
    ];

    public function __construct(array $options = [])
    {
        foreach ($options as $key => $value) {
            if (array_key_exists($key, $this->options) && $value !== null && $value !== '') {
                $this->options[$key] = $value;
            }
        }
    }

    /**
     * Detect the language of a plain text message.
     *
     * @return string One of the LANG_* constants (or the configured default language).
     */
    public function detect($text)
    {
        return $this->analyze($text)['language'];
    }

    /**
     * Detect the language and return the numbers the decision was based on.
     *
     * @return array ['language' => string, 'counts' => array, 'cjk_ratio' => float, 'kana_ratio' => float, 'hangul_ratio' => float]
     */
    public function analyze($text)
    {
        $prepared = $this->prepareText((string) $text);
        $counts = $this->countScripts($prepared);

        $result = [
            'language'     => $this->options['default_language'],
            'counts'       => $counts,
            'cjk_ratio'    => 0.0,
            'kana_ratio'   => 0.0,
            'hangul_ratio' => 0.0,
        ];

        $cjk = $counts['kana'] + $counts['hangul'] + $counts['han'];

        if ($cjk === 0) {
            return $result;
        }

        // Latin (and other alphabetic) letters are weighted per word, CJK characters per character.
        $lettersPerWord = max(1, (float) $this->options['letters_per_word']);
        $alphabetic = ($counts['latin'] + $counts['other']) / $lettersPerWord;

        $result['cjk_ratio'] = $cjk / ($cjk + $alphabetic);
        $result['kana_ratio'] = $counts['kana'] / $cjk;
        $result['hangul_ratio'] = $counts['hangul'] / $cjk;

        if ($result['cjk_ratio'] < (float) $this->options['min_cjk_ratio']) {
            return $result;
        }

        if ($counts['hangul'] > 0
            && $result['hangul_ratio'] >= (float) $this->options['min_hangul_ratio']
            && $counts['hangul'] >= $counts['kana']
        ) {
            $result['language'] = self::LANG_KOREAN;
        } elseif ($this->looksJapanese($counts, $result['kana_ratio'])) {
            $result['language'] = self::LANG_JAPANESE;
        } elseif ($counts['han'] > 0) {
            $result['language'] = self::LANG_CHINESE;
        } else {
            $result['language'] = $counts['hangul'] >= $counts['kana'] ? self::LANG_KOREAN : self::LANG_JAPANESE;
        }

        return $result;
    }

    /**
     * Japanese needs kana. Hiragana is decisive (Chinese never uses it); katakana
     * alone only counts when it makes up a large part of the text, so a Chinese
     * message mentioning a katakana brand name is not taken for Japanese.
     */
    protected function looksJapanese(array $counts, $kanaRatio)
    {
        if ($counts['kana'] === 0) {
            return false;
        }

        if ($counts['hiragana'] > 0 && $kanaRatio >= (float) $this->options['min_kana_ratio']) {
            return true;
        }

        return $kanaRatio >= (float) $this->options['katakana_only_ratio'];
    }

    /**
     * Simplified or Traditional Chinese: which of the two the characters
     * that differ between them belong to (Simplified when undecided).
     * Null without PHP's intl extension.
     */
    public function chineseVariant($text)
    {
        if (!class_exists('Transliterator')) {
            return null;
        }
        $to_simplified = \Transliterator::create('Hant-Hans');
        $to_traditional = \Transliterator::create('Hans-Hant');
        if (!$to_simplified || !$to_traditional) {
            return null;
        }

        $simplified = 0;
        $traditional = 0;
        preg_match_all('/\p{Han}/u', $this->prepareText($text), $chars);
        foreach (array_unique($chars[0]) as $char) {
            if ($to_simplified->transliterate($char) !== $char) {
                $traditional++;
            } elseif ($to_traditional->transliterate($char) !== $char) {
                $simplified++;
            }
        }

        return $traditional > $simplified ? self::LANG_CHINESE_TRADITIONAL : self::LANG_CHINESE_SIMPLIFIED;
    }

    /**
     * Count characters per script.
     *
     * @return array ['hiragana' => int, 'katakana' => int, 'kana' => int, 'hangul' => int, 'han' => int, 'latin' => int, 'other' => int, 'letters' => int]
     */
    public function countScripts($text)
    {
        $text = (string) $text;

        $hiragana = preg_match_all('/\p{Hiragana}/u', $text);
        $katakana = preg_match_all('/\p{Katakana}/u', $text);
        $hangul   = preg_match_all('/\p{Hangul}/u', $text);
        $han      = preg_match_all('/[\p{Han}\p{Bopomofo}]/u', $text);
        $latin    = preg_match_all('/\p{Latin}/u', $text);
        $letters  = preg_match_all('/\p{L}/u', $text);

        // preg_match_all() returns false on invalid UTF-8.
        foreach (['hiragana', 'katakana', 'hangul', 'han', 'latin', 'letters'] as $var) {
            if ($$var === false) {
                $$var = 0;
            }
        }

        $kana = $hiragana + $katakana;
        $other = max(0, $letters - $kana - $hangul - $han - $latin);

        return compact('hiragana', 'katakana', 'kana', 'hangul', 'han', 'latin', 'other', 'letters');
    }

    /**
     * Remove the parts of a plain text message that say nothing about the
     * sender's language: URLs, email addresses, quoted messages and signatures.
     */
    public function prepareText($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        // URLs and email addresses are always Latin and would skew the result.
        $text = preg_replace('~(?:https?|ftp)://\S+|\bwww\.\S+~iu', ' ', $text) ?? $text;
        $text = preg_replace('~[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+~u', ' ', $text) ?? $text;

        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            $line = $this->trimLine($line);

            if ($line === '') {
                continue;
            }

            if ($this->matchesAny($line, self::QUOTE_START_PATTERNS) || $this->matchesAny($line, self::SIGNATURE_START_PATTERNS)) {
                break;
            }

            // Quoted line.
            if (mb_substr($line, 0, 1) === '>') {
                continue;
            }

            $kept[] = $line;
        }

        $result = implode("\n", $kept);

        // If stripping removed everything, fall back to the whole text.
        if (trim($result) === '') {
            $result = implode("\n", array_filter(array_map([$this, 'trimLine'], $lines), 'strlen'));
        }

        return mb_substr($result, 0, (int) $this->options['max_length']);
    }

    protected function trimLine($line)
    {
        return preg_replace('/^[\s\x{00A0}\x{3000}]+|[\s\x{00A0}\x{3000}]+$/u', '', (string) $line) ?? trim((string) $line);
    }

    protected function matchesAny($line, array $patterns)
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }
}

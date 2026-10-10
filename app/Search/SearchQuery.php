<?php

namespace App\Search;

/**
 * What a search box query asks for: words and "phrases" (all must match,
 * -excluded ones must not), words for a field (from:, to:, subject:) and
 * conditions (mailbox:, is:, has:, before:, after:).
 */
class SearchQuery
{
    /**
     * Operators that search a field of the index.
     */
    const FIELDS = [
        'from'    => 'people',
        'to'      => 'recipients',
        'subject' => 'subject',
    ];

    /**
     * Operators that are conditions.
     */
    const CONDITIONS = ['mailbox', 'is', 'has', 'attachment', 'before', 'after'];

    /**
     * Scripts written without spaces between words.
     */
    const NO_SPACES = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

    /**
     * Words and phrases: ['text', 'phrase' (bool), 'exclude' (bool), 'field' (null for any)].
     */
    public $terms = [];

    /**
     * Conditions: ['mailbox' => [...], 'is' => [...], 'has' => [...], 'before' => [...], 'after' => [...]].
     */
    public $conditions = [];

    /**
     * The query as typed.
     */
    public $q = '';

    public static function parse($q)
    {
        $query = new self();
        $query->q = trim((string) $q);

        preg_match_all('/(-?)(?:([a-z]+):)?(?:"([^"]*)"?|(\S+))/iu', $query->q, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $exclude = $match[1] === '-';
            $operator = strtolower($match[2] ?? '');
            $phrase = isset($match[3]) && $match[3] !== '' && ($match[4] ?? '') === '';
            $text = trim($phrase ? $match[3] : ($match[4] ?? ''));

            if ($operator !== '' && !isset(self::FIELDS[$operator]) && !in_array($operator, self::CONDITIONS)) {
                // Not an operator ("https://", "10:30"): part of the word.
                $text = $match[2].':'.$text;
                $operator = '';
            }
            if ($text === '' || $text === '-') {
                continue;
            }

            if (in_array($operator, self::CONDITIONS)) {
                $query->conditions[$operator][] = $text;
                continue;
            }
            $query->terms[] = [
                'text'    => preg_replace('/\s+/u', ' ', $text),
                'phrase'  => $phrase,
                'exclude' => $exclude,
                'field'   => self::FIELDS[$operator] ?? null,
            ];
        }

        return $query;
    }

    /**
     * Whether the query has words to look for (not only conditions or exclusions).
     */
    public function hasTerms()
    {
        foreach ($this->terms as $term) {
            if (!$term['exclude']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A conversation number, when the query is just that.
     */
    public function number()
    {
        if (count($this->terms) == 1 && !$this->conditions && !$this->terms[0]['exclude']
            && !$this->terms[0]['field'] && preg_match('/^#?(\d{1,9})$/', $this->terms[0]['text'], $m)
        ) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * A term's words as the full-text index splits them.
     */
    public static function tokens($text)
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text))));
    }

    /**
     * Whether the full-text index can find a term: MariaDB doesn't index
     * words shorter than innodb_ft_min_token_size, and doesn't split
     * scripts written without spaces (Chinese, Japanese, Thai...).
     */
    public static function isIndexable($text, $min_length = 3)
    {
        if (preg_match(self::NO_SPACES, $text)) {
            return false;
        }
        $tokens = self::tokens($text);
        if (!$tokens) {
            return false;
        }
        foreach ($tokens as $token) {
            if (mb_strlen($token) < $min_length || mb_strlen($token) > 84) {
                return false;
            }
        }

        return true;
    }

    /**
     * A term in MariaDB's boolean full-text syntax: a word matches words
     * starting with it, several words (or a phrase) match in that order.
     */
    public static function booleanTerm($term)
    {
        $tokens = self::tokens($term['text']);
        $sign = $term['exclude'] ? '-' : '+';
        if (count($tokens) == 1 && !$term['phrase']) {
            return $sign.$tokens[0].($term['exclude'] ? '' : '*');
        }

        return $sign.'"'.implode(' ', $tokens).'"';
    }

    /**
     * A text's words as the PostgreSQL index keeps them: in lower case,
     * without accents (with the intl extension), split at anything but
     * letters and digits.
     */
    public static function words($text)
    {
        $text = mb_strtolower((string) $text);
        if (class_exists(\Normalizer::class) && ($decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D)) !== false) {
            $text = preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], 'strlen'));
    }

    /**
     * A term in PostgreSQL's tsquery syntax, in the column with $weight
     * (A subject, B people, C recipients; any when empty), as booleanTerm().
     */
    public static function tsqueryTerm($term, $weight = '')
    {
        $words = self::words($term['text']);
        if (count($words) == 1 && !$term['phrase'] && !$term['exclude']) {
            return $words[0].':*'.$weight;
        }
        $words = array_map(function ($word) use ($weight) {
            return $word.($weight ? ':'.$weight : '');
        }, $words);

        return ($term['exclude'] ? '!' : '').'('.implode(' <-> ', $words).')';
    }

    /**
     * A term in SQLite's FTS5 syntax, in $column (any when empty), as
     * booleanTerm(); fts5Query() adds AND and NOT.
     */
    public static function fts5Term($term, $column = '')
    {
        $tokens = self::tokens($term['text']);
        $query = ($column ? $column.' : ' : '').'"'.implode(' ', $tokens).'"';

        return $query.(count($tokens) == 1 && !$term['phrase'] && !$term['exclude'] ? '*' : '');
    }

    /**
     * Terms in SQLite's FTS5 syntax: all of them, none of the excluded ones.
     */
    public static function fts5Query($terms, $column = '')
    {
        $positive = [];
        $negative = '';
        foreach ($terms as $term) {
            if ($term['exclude']) {
                $negative .= ' NOT '.self::fts5Term($term, $column);
            } else {
                $positive[] = self::fts5Term($term, $column);
            }
        }

        return '('.implode(' AND ', $positive).')'.$negative;
    }
}

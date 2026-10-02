<?php

namespace App\Incoming;

/**
 * An email address from a header: "personal" is the display name.
 */
class Address
{
    /**
     * The email address.
     *
     * @var string
     */
    public $mail;

    /**
     * The display name, or ''.
     *
     * @var string
     */
    public $personal;

    public function __construct($mail, $personal = '')
    {
        $this->mail = (string) $mail;
        $this->personal = (string) $personal;
    }

    /**
     * The addresses in an address header's value (From, To, Cc, ...).
     *
     * Groups ("Undisclosed recipients:;") give their members, empty
     * addresses ("<>") are left out, and an address without a host gets
     * "@unknown" so the email isn't lost (FetchEmails knows that host).
     *
     * @return Address[]
     */
    public static function parseList($value)
    {
        $value = preg_replace('/\r?\n(?=[ \t])/', '', (string) $value);

        // Split on commas outside quotes, comments and angle brackets.
        $entries = [];
        $entry = ['text' => '', 'comment' => ''];
        $quoted = $angle = false;
        $comment = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if (($quoted || $comment) && $char === '\\' && $i + 1 < $length) {
                $entry[$comment ? 'comment' : 'text'] .= $char.$value[++$i];
                continue;
            }
            if ($comment) {
                $comment += ($char === '(') - ($char === ')');
                if ($comment) {
                    $entry['comment'] .= $char;
                }
                continue;
            }
            if ($quoted) {
                $quoted = $char !== '"';
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === '(') {
                $comment = 1;
                continue;
            } elseif ($char === '<' || $char === '>') {
                $angle = $char === '<';
            } elseif (!$angle && ($char === ',' || $char === ';')) {
                $entries[] = $entry;
                $entry = ['text' => '', 'comment' => ''];
                continue;
            } elseif (!$angle && $char === ':') {
                // A group's name.
                $entry = ['text' => '', 'comment' => ''];
                continue;
            }
            $entry['text'] .= $char;
        }
        $entries[] = $entry;

        $addresses = [];
        foreach ($entries as $entry) {
            $text = trim($entry['text']);
            if (preg_match('/^(.*)<([^<>]*)>?$/s', $text, $m)) {
                $personal = trim($m[1]);
                $mail = trim($m[2]);
            } else {
                $personal = '';
                $mail = $text;
            }
            // "name host.tld name@host.tld": the part that is an address.
            if (preg_match('/\s/', $mail)) {
                $words = preg_split('/\s+/', $mail);
                $mail = current(array_filter($words, function ($word) {
                    return str_contains($word, '@');
                })) ?: implode('', $words);
            }
            if ($mail === '') {
                continue;
            }
            if (!str_contains($mail, '@')) {
                $mail .= '@unknown';
            }

            if ($personal === '') {
                $personal = trim($entry['comment']);
            }
            if (preg_match('/^"(.*)"$/s', $personal, $m)) {
                $personal = preg_replace('/\\\\(.)/s', '$1', $m[1]);
            } elseif (preg_match("/^'(.*)'$/s", $personal, $m)) {
                $personal = $m[1];
            }

            $addresses[] = new self($mail, trim(HeaderText::decode($personal)));
        }

        return $addresses;
    }
}

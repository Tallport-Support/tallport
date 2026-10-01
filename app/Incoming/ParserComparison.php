<?php

namespace App\Incoming;

/**
 * Compares what two IncomingMessage implementations read from the same email
 * (today: FreeScout's patched webklex 4.1.1 and webklex 6), to find which of
 * FreeScout's changes to the library still make a difference.
 * Reports field names only, never content.
 */
class ParserComparison
{
    /**
     * Read an email with both parsers and list the fields that differ.
     *
     * @param  string  $raw  The email source.
     * @return string[] Field names, e.g. ["subject", "attachments"]; ["error: ..."] if a parser failed.
     */
    public static function compareRaw($raw)
    {
        try {
            $legacy = self::legacy($raw);
            $legacy_values = self::values($legacy);
        } catch (\Throwable $e) {
            $legacy_values = ['error' => get_class($e)];
        }
        try {
            $new_values = self::values(new Webklex6Message($raw));
        } catch (\Throwable $e) {
            $new_values = ['error' => get_class($e)];
        }

        if (isset($legacy_values['error']) || isset($new_values['error'])) {
            return ($legacy_values['error'] ?? null) === ($new_values['error'] ?? null)
                ? []
                : ['error: '.($legacy_values['error'] ?? 'none').' / '.($new_values['error'] ?? 'none')];
        }

        return self::differences($legacy_values, $new_values);
    }

    public static function legacy($raw)
    {
        if (!str_contains($raw, "\r\n")) {
            $raw = str_replace("\n", "\r\n", $raw);
        }
        new \App\LegacyImap\ClientManager(config('imap'));

        return new LegacyImapMessage(\App\LegacyImap\Message::fromString($raw));
    }

    /**
     * The fields whose values differ.
     *
     * @return string[]
     */
    public static function differences(array $a, array $b)
    {
        $fields = [];
        foreach ($a as $field => $value) {
            if ($value !== $b[$field]) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * What Tallport uses from a message, as comparable plain values.
     */
    public static function values(IncomingMessage $message)
    {
        $addresses = function (array $list) {
            return array_map(function (Address $address) {
                return [\App\Email::sanitizeEmail($address->mail), trim($address->personal)];
            }, $list);
        };
        $ids = function ($value) {
            return array_values(array_filter(preg_split('/[, <>]/', (string) $value)));
        };

        return [
            'message_id'  => trim($message->messageId(), '<> '),
            'from'        => $addresses($message->from()),
            'reply_to'    => $addresses($message->replyTo()),
            'to'          => $addresses($message->to()),
            'cc'          => $addresses($message->cc()),
            'bcc'         => $addresses($message->bcc()),
            'subject'     => $message->subject(),
            'date'        => $message->date() ? $message->date()->format('Y-m-d H:i:s P') : null,
            'in_reply_to' => $ids($message->inReplyTo()),
            'references'  => $ids($message->references()),
            'html_body'   => $message->htmlBody(),
            'text_body'   => (string) $message->textBody(),
            'attachments' => array_map(function (Attachment $attachment) {
                return [
                    (string) $attachment->getName(),
                    strtolower((string) $attachment->getType()),
                    strtolower(trim(explode(';', (string) $attachment->content_type)[0])),
                    md5((string) $attachment->getContent()),
                    (string) $attachment->id,
                ];
            }, $message->attachments()),
        ];
    }
}

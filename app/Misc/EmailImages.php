<?php

namespace App\Misc;

use App\Attachment;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Images in outgoing emails are never linked: an image stored in Tallport (an
 * attachment's address, as the editor puts it in) goes in the email inline,
 * and any other linked image is left out (its alt text stays). Images already
 * in the email (data: and cid:) stay, and so does the open tracking image
 * when open tracking is on. Applied to every email as it's sent.
 */
class EmailImages
{
    public static function embed(Email $email)
    {
        $html = $email->getHtmlBody();
        if (!is_string($html) || stripos($html, '<img') === false) {
            return;
        }

        $parts = [];
        $html = preg_replace_callback('/<img\b[^>]*>/i', function ($match) use ($email, &$parts) {
            $tag = $match[0];
            if (!preg_match('/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $src_match)) {
                return $tag;
            }
            $src = html_entity_decode($src_match[1] !== '' ? $src_match[1] : ($src_match[2] ?? ''), ENT_QUOTES | ENT_HTML5);
            if (preg_match('/^(data|cid):/i', $src) || self::isOpenTracking($src)) {
                return $tag;
            }

            $attachment = self::attachment($src);
            if ($attachment) {
                if (!isset($parts[$attachment->id])) {
                    $contents = $attachment->getFileContents();
                    if ($contents === '' || $contents === null) {
                        return self::alt($tag);
                    }
                    $part = (new DataPart($contents, $attachment->file_name, $attachment->mime_type ?: null))->asInline();
                    $email->addPart($part);
                    $parts[$attachment->id] = $part->getContentId();
                }

                return str_replace($src_match[0], ' src="cid:'.$parts[$attachment->id].'"', $tag);
            }

            return self::alt($tag);
        }, $html) ?? $html;

        $email->html($html);
    }

    /**
     * The attachment an address is (its id and token checked), or null.
     */
    public static function attachment($url)
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (empty($query['id']) || empty($query['token']) || !is_string($query['token']) || !str_contains((string) parse_url($url, PHP_URL_PATH), '/'.Attachment::DIRECTORY.'/')) {
            return null;
        }
        $attachment = Attachment::find((int) $query['id']);

        return $attachment && hash_equals($attachment->getToken(), $query['token']) ? $attachment : null;
    }

    protected static function isOpenTracking($src)
    {
        return \App\Option::get('open_tracking') && str_starts_with($src, url('/thread/read/'));
    }

    /**
     * An image left out: its alt text, if it has one.
     */
    protected static function alt($tag)
    {
        if (preg_match('/\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $alt_match)) {
            return $alt_match[1] !== '' ? $alt_match[1] : ($alt_match[2] ?? '');
        }

        return '';
    }
}

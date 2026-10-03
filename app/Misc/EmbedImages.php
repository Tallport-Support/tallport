<?php

namespace App\Misc;

use App\Attachment;
use Symfony\Component\Mime\Email;

/**
 * Images in outgoing emails that are on this help desk (attachments,
 * uploaded images such as signature logos) or inline (data:) are sent in
 * the email itself (cid:), so recipients see them without loading
 * anything from here. Other images stay links.
 */
class EmbedImages
{
    public static function listen()
    {
        \Eventy::addFilter('mail.process_swift_message', function ($send, $message) {
            if ($send && $message instanceof Email) {
                try {
                    self::embed($message);
                } catch (\Throwable $e) {
                    // The images stay links.
                    \Helper::logException($e, '[Embed images]');
                }
            }

            return $send;
        }, 20, 2);

        // Embedded images make the email bigger: they count toward the
        // largest message size.
        \Eventy::addFilter('attachments.embedded.check_size', function () {
            return true;
        }, 20, 1);
    }

    /**
     * Embed the images of an email; returns how many files were embedded.
     */
    public static function embed(Email $email)
    {
        $html = $email->getHtmlBody();
        if (!is_string($html) || stripos($html, '<img') === false) {
            return 0;
        }
        $app_host = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $embedded = [];

        $html = preg_replace_callback('/(<img\b[^>]*?\ssrc\s*=\s*)(["\'])(.*?)\2/is', function ($match) use ($email, $app_host, &$embedded) {
            $src = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5);
            if (!isset($embedded[$src])) {
                $image = self::image($src, $app_host);
                if (!$image) {
                    return $match[0];
                }
                $name = 'image'.(count($embedded) + 1).'-'.\Str::random(10).'.'.self::extension($image['mime']);
                $email->embed($image['data'], $name, $image['mime']);
                $embedded[$src] = $name;
            }

            return $match[1].$match[2].'cid:'.$embedded[$src].$match[2];
        }, $html);

        if ($embedded) {
            $email->html($html);
        }

        return count($embedded);
    }

    /**
     * The image a src stands for: ['data', 'mime'], or null.
     */
    protected static function image($src, $app_host)
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', trim($src), $match)) {
            $data = base64_decode(preg_replace('/\s+/', '', $match[2]), true);

            return $data ? ['data' => $data, 'mime' => strtolower($match[1])] : null;
        }

        $url = parse_url($src);
        if (empty($url['host']) || empty($url['path']) || strtolower($url['host']) !== $app_host) {
            return null;
        }

        // An attachment (/storage/attachment/...?id=..&token=..).
        if (str_contains($url['path'], '/'.Attachment::DIRECTORY.'/')) {
            parse_str($url['query'] ?? '', $query);
            $attachment = !empty($query['id']) ? Attachment::find((int) $query['id']) : null;
            if (!$attachment || empty($query['token']) || !hash_equals((string) $attachment->getToken(), (string) $query['token'])
                || !str_starts_with(strtolower((string) $attachment->mime_type), 'image/')
            ) {
                return null;
            }
            $data = $attachment->getFileContents();

            return $data ? ['data' => $data, 'mime' => strtolower($attachment->mime_type)] : null;
        }

        // An uploaded image (/storage/uploads/name).
        if (str_contains($url['path'], '/uploads/')) {
            $name = basename($url['path']);
            if (parse_url(\Helper::uploadedFileUrl($name), PHP_URL_PATH) !== $url['path']) {
                return null;
            }
            $storage = \Helper::getPublicStorage();
            if (!$storage->exists('uploads/'.$name)) {
                return null;
            }
            $mime = (string) $storage->mimeType('uploads/'.$name);

            return str_starts_with($mime, 'image/') ? ['data' => $storage->get('uploads/'.$name), 'mime' => $mime] : null;
        }

        return null;
    }

    protected static function extension($mime)
    {
        $extension = strtolower(preg_replace('#^image/([a-z0-9]+).*$#i', '$1', $mime));

        return $extension == 'jpeg' ? 'jpg' : ($extension ?: 'img');
    }
}

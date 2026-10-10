<?php

namespace App\Misc;

use App\Attachment;
use Illuminate\Support\Facades\Process;

/**
 * Pictures made from image attachments: thumbnails for a message's attachment
 * list (GD, from JPEG, PNG, GIF and WebP) and HEIC/HEIF photos (iPhones)
 * converted to JPEG with ImageMagick, when the server can, for the viewer and
 * the thumbnails. They are made on first request, kept on the attachments'
 * disk under attachment_previews/ and deleted with the attachment
 * (Attachment::deleteForever()). Where the server can't convert HEIC,
 * browsers decode it (public/js/attachments.js).
 */
class AttachmentImages
{
    const DIRECTORY = 'attachment_previews';

    /**
     * Thumbnails are this high (160 CSS pixels at 2x) and at most twice as wide.
     */
    const THUMBNAIL_HEIGHT = 320;

    /**
     * Larger files and pictures aren't decoded, to keep memory in bounds.
     */
    const MAX_FILE_SIZE = 25 * 1024 * 1024;

    const MAX_PIXELS = 40000000;

    /**
     * The longest side of a converted HEIC photo.
     */
    const CONVERTED_MAX_SIDE = 4096;

    const CONVERTER_CACHE = 'heic_converter';

    public static $thumbnail_mime_types = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'];

    public static $thumbnail_extensions = ['jpg', 'jpeg', 'jfif', 'pjpeg', 'pjp', 'png', 'gif', 'webp'];

    public static $heic_mime_types = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    public static $heic_extensions = ['heic', 'heif'];

    /**
     * An iPhone photo: by its type, or by its name when it came as application/octet-stream.
     */
    public static function isHeic(Attachment $attachment)
    {
        return in_array(strtolower((string) $attachment->mime_type), self::$heic_mime_types)
            || in_array(self::extension($attachment), self::$heic_extensions);
    }

    /**
     * Whether a message's attachment list shows the file as a thumbnail.
     * Team chat files are stored encrypted and have none.
     */
    public static function hasThumbnail(Attachment $attachment)
    {
        if ($attachment->team_message_id || !$attachment->size || $attachment->size > self::MAX_FILE_SIZE) {
            return false;
        }

        return self::isHeic($attachment)
            || in_array(strtolower((string) $attachment->mime_type), self::$thumbnail_mime_types)
            || (str_starts_with(strtolower((string) $attachment->mime_type), 'image/') && in_array(self::extension($attachment), self::$thumbnail_extensions));
    }

    /**
     * The thumbnail's address (a HEIC photo has one where the server converts them).
     */
    public static function thumbnailUrl(Attachment $attachment)
    {
        return route('attachments.thumbnail', ['id' => $attachment->id, 'token' => $attachment->getToken()]);
    }

    /**
     * A HEIC photo as JPEG for the viewer, or null where the server can't convert it.
     */
    public static function convertedUrl(Attachment $attachment)
    {
        if (!self::isHeic($attachment) || !self::converter()) {
            return null;
        }

        return route('attachments.converted', ['id' => $attachment->id, 'token' => $attachment->getToken()]);
    }

    /**
     * Where an attachment's pictures are kept: [thumbnail, converted].
     */
    public static function paths(Attachment $attachment)
    {
        $base = self::DIRECTORY.DIRECTORY_SEPARATOR.$attachment->file_dir.$attachment->id;

        return [$base.'-thumbnail.jpg', $base.'-converted.jpg'];
    }

    /**
     * The thumbnail's path on the attachments' disk, made now if needed; null when
     * there is none (not an image, too large, unreadable). A failed attempt is
     * remembered as an empty file.
     */
    public static function thumbnail(Attachment $attachment)
    {
        if (!self::hasThumbnail($attachment)) {
            return null;
        }
        $path = self::paths($attachment)[0];
        $disk = Attachment::getDisk();
        if ($disk->exists($path)) {
            return $disk->size($path) ? $path : null;
        }

        if (self::isHeic($attachment)) {
            $source = self::converted($attachment);
            if (!$source) {
                return null;
            }
        } else {
            $source = $attachment->getStorageFilePath();
        }

        $thumb = null;
        $file = self::localCopy($source);
        if ($file) {
            $size = @getimagesize($file[0]);
            if ($size && in_array($size['mime'], self::$thumbnail_mime_types) && self::fitsInMemory($size[0], $size[1])) {
                $thumb = \Helper::resizeImage($file[0], $size['mime'], 0, self::THUMBNAIL_HEIGHT);
            }
            if ($file[1]) {
                @unlink($file[0]);
            }
        }

        $content = '';
        if ($thumb) {
            ob_start();
            imagejpeg($thumb, null, 82);
            $content = ob_get_clean();
        }
        $disk->put($path, $content);

        return $content !== '' ? $path : null;
    }

    /**
     * A HEIC photo converted to JPEG (upright, without metadata, at most
     * CONVERTED_MAX_SIDE pixels), made now if needed; null when the server
     * can't convert it.
     */
    public static function converted(Attachment $attachment)
    {
        if (!self::isHeic($attachment) || $attachment->team_message_id || $attachment->size > self::MAX_FILE_SIZE) {
            return null;
        }
        $path = self::paths($attachment)[1];
        $disk = Attachment::getDisk();
        if ($disk->exists($path)) {
            return $disk->size($path) ? $path : null;
        }
        $converter = self::converter();
        if (!$converter) {
            return null;
        }

        $file = self::localCopy($attachment->getStorageFilePath());
        if (!$file) {
            return null;
        }
        $output = \Helper::getTempFileName();
        // The coder is named, so ImageMagick doesn't guess the format from the content.
        $result = Process::timeout(60)->run([
            $converter, 'heic:'.$file[0],
            '-auto-orient', '-strip',
            '-resize', self::CONVERTED_MAX_SIDE.'x'.self::CONVERTED_MAX_SIDE.'>',
            '-quality', '85',
            'jpeg:'.$output,
        ]);
        if ($file[1]) {
            @unlink($file[0]);
        }
        $content = $result->successful() && is_file($output) ? (string) file_get_contents($output) : '';
        @unlink($output);
        if ($content === '' || !str_starts_with($content, "\xFF\xD8")) {
            if (!$result->successful()) {
                \Log::warning('Could not convert HEIC attachment', ['attachment_id' => $attachment->id, 'error' => mb_substr($result->errorOutput(), 0, 500)]);
            }
            $content = '';
        }
        $disk->put($path, $content);

        return $content !== '' ? $path : null;
    }

    /**
     * The ImageMagick command that can read HEIC, as detectConverter(); checked
     * once a day, or now.
     */
    public static function converter($fresh = false)
    {
        if ($fresh) {
            $converter = self::detectConverter();
            \Cache::put(self::CONVERTER_CACHE, $converter, now()->addDay());

            return $converter;
        }

        return \Cache::remember(self::CONVERTER_CACHE, now()->addDay(), function () {
            return self::detectConverter();
        });
    }

    /**
     * The ImageMagick command that can read HEIC (magick, or convert of
     * ImageMagick 6), or '' when there is none.
     */
    public static function detectConverter()
    {
        foreach (['magick', 'convert'] as $command) {
            try {
                $result = Process::timeout(10)->run([$command, '-list', 'format']);
            } catch (\Throwable $e) {
                continue;
            }
            // "     HEIC  HEIC      r--   High Efficiency Image Format"
            if ($result->successful() && preg_match('/^\s*HEIC\*?\s+\S+\s+r/m', $result->output())) {
                return $command;
            }
        }

        return '';
    }

    /**
     * Whether GD can hold a picture this large and its resized copy.
     */
    public static function fitsInMemory($width, $height)
    {
        $pixels = $width * $height;
        if (!$pixels || $pixels > self::MAX_PIXELS) {
            return false;
        }
        $limit = \Helper::phpIniSizeToBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        // Truecolor pixels take 4 bytes; turning a photo upright needs a second copy.
        return memory_get_usage() + $pixels * 10 < $limit;
    }

    /**
     * A file on the attachments' disk as a local file: [path, whether it's a temporary copy].
     */
    protected static function localCopy($path)
    {
        $disk = Attachment::getDisk();
        if (!$disk->exists($path)) {
            return null;
        }
        if (\Helper::isLocalStorage(Attachment::getDiskName())) {
            return [$disk->path($path), false];
        }
        $temp = \Helper::getTempFileName();
        $stream = $disk->readStream($path);
        file_put_contents($temp, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return [$temp, true];
    }

    protected static function extension(Attachment $attachment)
    {
        return strtolower(pathinfo((string) $attachment->file_name, PATHINFO_EXTENSION));
    }
}

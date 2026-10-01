<?php

namespace App\Incoming;

use App\Thread;

/**
 * The raw source of each incoming email, kept for a while after it is saved
 * (storage/app/incoming-mail/<thread id>.eml, APP_INCOMING_MAIL_RETENTION_DAYS,
 * default 30, 0 = don't keep), so a message can be looked at or imported
 * again with tallport:receive.
 */
class RawSources
{
    const FOLDER = 'incoming-mail';

    public static function retentionDays()
    {
        return (int) config('app.incoming_mail_retention_days');
    }

    public static function path(Thread $thread)
    {
        return storage_path('app/'.self::FOLDER.'/'.$thread->id.'.eml');
    }

    /**
     * Keep the source of a saved thread. Never stops fetching: problems are
     * logged.
     */
    public static function store(Thread $thread, IncomingMessage $message)
    {
        if (self::retentionDays() <= 0) {
            return;
        }

        try {
            $path = self::path($thread);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $message->rawSource());
        } catch (\Throwable $e) {
            \Helper::logException($e, '[Incoming mail] Could not keep the raw source of thread '.$thread->id.':');
        }
    }

    /**
     * Delete sources older than the retention period.
     *
     * @return int Number of files deleted.
     */
    public static function clean()
    {
        $deleted = 0;
        $days = self::retentionDays();
        $limit = time() - max($days, 0) * 86400;

        foreach (glob(storage_path('app/'.self::FOLDER.'/*.eml')) ?: [] as $file) {
            if ($days <= 0 || filemtime($file) < $limit) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

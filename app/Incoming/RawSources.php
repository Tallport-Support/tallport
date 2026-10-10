<?php

namespace App\Incoming;

use App\Thread;

/**
 * The raw source of each incoming email, kept gzip-compressed in the database
 * (thread_sources) for as long as its thread exists, for Show Original and
 * Download .eml (which tallport:receive can import again).
 */
class RawSources
{
    const TABLE = 'thread_sources';

    /**
     * Keep the source of a saved thread. Never stops fetching: problems are
     * logged.
     */
    public static function store(Thread $thread, IncomingMessage $message)
    {
        try {
            self::put($thread->id, $message->rawSource());
        } catch (\Throwable $e) {
            \Helper::logException($e, '[Incoming mail] Could not keep the raw source of thread '.$thread->id.':');
        }
    }

    /**
     * Save (or replace) a thread's source.
     */
    public static function put($thread_id, $raw)
    {
        // Bound as a stream (a LOB): PostgreSQL's bytea doesn't take binary text.
        $source = fopen('php://memory', 'r+b');
        fwrite($source, gzencode((string) $raw));
        rewind($source);

        try {
            \DB::transaction(function () use ($thread_id, $source) {
                \DB::table(self::TABLE)->where('thread_id', $thread_id)->delete();
                \DB::table(self::TABLE)->insert(['thread_id' => $thread_id, 'source' => $source]);
            });
        } finally {
            fclose($source);
        }
    }

    /**
     * The email as it came in, or null if it isn't stored (older threads,
     * outgoing ones).
     *
     * @return string|null
     */
    public static function get(Thread $thread)
    {
        $source = \DB::table(self::TABLE)->where('thread_id', $thread->id)->value('source');
        if ($source === null) {
            return null;
        }
        // PostgreSQL returns bytea as a stream.
        if (is_resource($source)) {
            $source = stream_get_contents($source);
        }
        $raw = @gzdecode((string) $source);

        return $raw === false ? null : $raw;
    }

    /**
     * Delete the sources of threads being deleted (the foreign key does it too
     * where the database enforces it).
     */
    public static function deleteByThreadIds($thread_ids)
    {
        foreach (array_chunk((array) $thread_ids, \Helper::IN_LIMIT) as $ids) {
            \DB::table(self::TABLE)->whereIn('thread_id', $ids)->delete();
        }
    }
}

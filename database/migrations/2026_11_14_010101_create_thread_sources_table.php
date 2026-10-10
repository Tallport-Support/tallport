<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The raw source of each incoming email, gzip-compressed, kept as long as its
 * thread (App\Incoming\RawSources). The sources kept for 30 days in
 * storage/app/incoming-mail/<thread id>.eml are moved in, and the folder is
 * deleted.
 */
class CreateThreadSourcesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('thread_sources')) {
            Schema::create('thread_sources', function (Blueprint $table) {
                $table->unsignedInteger('thread_id')->primary();
                // blob on SQLite, bytea on PostgreSQL; MySQL's blob holds 64 KB (below).
                $table->binary('source');

                $table->foreign('thread_id')->references('id')->on('threads')->onDelete('cascade');
            });
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                DB::statement('ALTER TABLE '.DB::getTablePrefix().'thread_sources MODIFY source LONGBLOB NOT NULL');
            }
        }

        self::importFiles(storage_path('app/incoming-mail'));
    }

    public function down()
    {
        Schema::dropIfExists('thread_sources');
    }

    /**
     * Move the <thread id>.eml files of a folder into the table (those of
     * threads that still exist), then delete the folder. A file at a time,
     * so a big folder fits in memory; run again after an error, it carries on.
     */
    public static function importFiles($folder)
    {
        $dir = is_dir($folder) ? opendir($folder) : false;
        if (!$dir) {
            return;
        }
        $batch = [];
        while (($name = readdir($dir)) !== false) {
            if (preg_match('/^(\d+)\.eml$/', $name, $m)) {
                $batch[(int) $m[1]] = $folder.'/'.$name;
            }
            if (count($batch) >= 100) {
                self::importBatch($batch);
                $batch = [];
            }
        }
        closedir($dir);
        self::importBatch($batch);

        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($folder);
    }

    protected static function importBatch(array $files)
    {
        if (!$files) {
            return;
        }
        $thread_ids = DB::table('threads')->whereIn('id', array_keys($files))->pluck('id')->all();
        $imported = DB::table('thread_sources')->whereIn('thread_id', $thread_ids)->pluck('thread_id')->all();
        foreach ($files as $thread_id => $path) {
            if (in_array($thread_id, $thread_ids) && !in_array($thread_id, $imported)) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    \App\Incoming\RawSources::put($thread_id, $raw);
                }
            }
            @unlink($path);
        }
    }
}

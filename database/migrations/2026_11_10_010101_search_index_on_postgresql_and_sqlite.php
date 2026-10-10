<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The search index (conversation_search) on PostgreSQL and SQLite, as MariaDB
 * and MySQL have it with their full-text indexes (App\Search):
 *
 * - PostgreSQL: a tsvector of each row's words (written by App\Search\Indexer),
 *   weighted by column, with a GIN index;
 * - SQLite: an FTS5 table over conversation_search, kept up to date by triggers.
 *
 * Existing rows are indexed again in the background; until then searches
 * work as before the index.
 */
class SearchIndexOnPostgresqlAndSqlite extends Migration
{
    public function up()
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['pgsql', 'sqlite'])) {
            return;
        }
        $table = DB::getTablePrefix().'conversation_search';

        if ($driver == 'pgsql') {
            DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS search_vector tsvector');
            DB::statement('CREATE INDEX IF NOT EXISTS '.$table.'_vector ON '.$table.' USING GIN (search_vector)');
        } else {
            $fts = $table.'_fts';
            // Words are letters, digits and "_", in any case and with or without accents.
            DB::statement('CREATE VIRTUAL TABLE IF NOT EXISTS '.$fts.' USING fts5(subject, people, recipients, content, '
                ."content='".$table."', content_rowid='conversation_id', tokenize=\"unicode61 tokenchars '_'\")");
            $columns = 'subject, people, recipients, content';
            $new = 'new.conversation_id, new.subject, new.people, new.recipients, new.content';
            $old = "'delete', old.conversation_id, old.subject, old.people, old.recipients, old.content";
            DB::statement('CREATE TRIGGER IF NOT EXISTS '.$fts.'_insert AFTER INSERT ON '.$table.' BEGIN '
                .'INSERT INTO '.$fts.'(rowid, '.$columns.') VALUES ('.$new.'); END');
            DB::statement('CREATE TRIGGER IF NOT EXISTS '.$fts.'_delete AFTER DELETE ON '.$table.' BEGIN '
                .'INSERT INTO '.$fts.'('.$fts.', rowid, '.$columns.') VALUES ('.$old.'); END');
            DB::statement('CREATE TRIGGER IF NOT EXISTS '.$fts.'_update AFTER UPDATE OF '.$columns.' ON '.$table.' BEGIN '
                .'INSERT INTO '.$fts.'('.$fts.', rowid, '.$columns.') VALUES ('.$old.'); '
                .'INSERT INTO '.$fts.'(rowid, '.$columns.') VALUES ('.$new.'); END');
            DB::statement('INSERT INTO '.$fts.'('.$fts.") VALUES ('rebuild')");
        }

        // Searches use the index once every row is in it again.
        DB::table('conversation_search')->update(['indexed_at' => null]);
        DB::table('options')->where('name', 'search_index_ready')->delete();
    }

    public function down()
    {
        $table = DB::getTablePrefix().'conversation_search';
        if (DB::getDriverName() == 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.$table.'_vector');
            DB::statement('ALTER TABLE '.$table.' DROP COLUMN IF EXISTS search_vector');
        } elseif (DB::getDriverName() == 'sqlite') {
            foreach (['insert', 'delete', 'update'] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$table.'_fts_'.$trigger);
            }
            DB::statement('DROP TABLE IF EXISTS '.$table.'_fts');
        }
    }
}

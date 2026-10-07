<?php

namespace Tests\Snapshot;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * The database schema produced by running all migrations, on MariaDB as in production. Read from
 * information_schema rather than SHOW CREATE TABLE, so it doesn't depend on
 * how a particular server version formats its DDL.
 */
class DatabaseSchemaTest extends TestCase
{
    use AssertsSnapshots;
    use \Tests\Concerns\UsesMariaDB;

    public function testSchema()
    {
        $database = DB::connection()->getDatabaseName();
        $schema = [];

        $columns = DB::select('
            SELECT table_name, column_name, column_type, is_nullable, column_default, extra, character_set_name, collation_name
            FROM information_schema.columns
            WHERE table_schema = ?
            ORDER BY table_name, ordinal_position', [$database]);
        foreach ($columns as $column) {
            $column = array_change_key_case((array)$column);
            $schema[$column['table_name']]['columns'][$column['column_name']] = array_filter([
                'type'      => $column['column_type'],
                'nullable'  => $column['is_nullable'] === 'YES',
                'default'   => $column['column_default'],
                'extra'     => $column['extra'],
                'charset'   => $column['character_set_name'],
                'collation' => $column['collation_name'],
            ], function ($value) {
                return $value !== null && $value !== '';
            });
        }

        $indexes = DB::select('
            SELECT table_name, index_name, non_unique, column_name, sub_part, index_type
            FROM information_schema.statistics
            WHERE table_schema = ?
            ORDER BY table_name, index_name, seq_in_index', [$database]);
        foreach ($indexes as $index) {
            $index = array_change_key_case((array)$index);
            $entry = &$schema[$index['table_name']]['indexes'][$index['index_name']];
            $entry['unique'] = !$index['non_unique'];
            $entry['type'] = $index['index_type'];
            $entry['columns'][] = $index['column_name'].($index['sub_part'] ? '('.$index['sub_part'].')' : '');
            unset($entry);
        }

        $foreign_keys = DB::select('
            SELECT table_name, constraint_name, column_name, referenced_table_name, referenced_column_name
            FROM information_schema.key_column_usage
            WHERE table_schema = ? AND referenced_table_name IS NOT NULL
            ORDER BY table_name, constraint_name, ordinal_position', [$database]);
        foreach ($foreign_keys as $foreign_key) {
            $foreign_key = array_change_key_case((array)$foreign_key);
            $schema[$foreign_key['table_name']]['foreign_keys'][$foreign_key['constraint_name']][] =
                $foreign_key['column_name'].' -> '.$foreign_key['referenced_table_name'].'.'.$foreign_key['referenced_column_name'];
        }

        ksort($schema);

        $this->assertMatchesSnapshot('database_schema', $schema);
    }
}

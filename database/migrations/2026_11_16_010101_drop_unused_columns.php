<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns nothing reads any more: FreeScout's unused or module-only settings,
 * the per-mailbox reply status (users.reply_status since 2026_10_19) and the
 * Nostr reopen window (mailbox meta chat_reopen_days since 2026_11_12).
 * Mobile notification subscriptions go too: there is no mobile channel.
 */
class DropUnusedColumns extends Migration
{
    /**
     * Each table's dropped columns, as down() adds them back.
     */
    protected function columns()
    {
        return [
            'mailboxes' => [
                'ticket_status'        => fn (Blueprint $table) => $table->unsignedTinyInteger('ticket_status')->default(2),
                'template'             => fn (Blueprint $table) => $table->unsignedTinyInteger('template')->default(1),
                'office_hours_enabled' => fn (Blueprint $table) => $table->boolean('office_hours_enabled')->default(false),
                'ratings'              => fn (Blueprint $table) => $table->boolean('ratings')->default(false),
                'ratings_placement'    => fn (Blueprint $table) => $table->unsignedTinyInteger('ratings_placement')->default(1),
                'ratings_text'         => fn (Blueprint $table) => $table->text('ratings_text')->nullable(),
            ],
            'users' => [
                'enable_kb_shortcuts' => fn (Blueprint $table) => $table->boolean('enable_kb_shortcuts')->default(true),
                'locked'              => fn (Blueprint $table) => $table->boolean('locked')->default(false),
            ],
            'followers' => [
                'added_by_user_id' => fn (Blueprint $table) => $table->integer('added_by_user_id')->nullable(),
            ],
            'modules' => [
                'license' => fn (Blueprint $table) => $table->text('license')->nullable(),
            ],
            'nostr_mailboxes' => [
                'reopen_days' => fn (Blueprint $table) => $table->unsignedSmallInteger('reopen_days')->default(30),
            ],
            'conversations' => [
                'read_by_user' => fn (Blueprint $table) => $table->boolean('read_by_user')->default(false),
            ],
            'aiassistant_draft_jobs' => [
                'document_limit' => fn (Blueprint $table) => $table->unsignedTinyInteger('document_limit')->default(0),
            ],
        ];
    }

    public function up()
    {
        foreach ($this->columns() as $table_name => $columns) {
            $existing = array_values(array_filter(array_keys($columns), fn ($column) => Schema::hasColumn($table_name, $column)));
            if ($existing) {
                Schema::table($table_name, function (Blueprint $table) use ($existing) {
                    $table->dropColumn($existing);
                });
            }
        }

        // Subscription::MEDIUM_MOBILE (3).
        DB::table('subscriptions')->where('medium', 3)->delete();
        $defaults = \Option::get('subscription_defaults', null);
        if (is_array($defaults) && array_key_exists(3, $defaults)) {
            unset($defaults[3]);
            \Option::set('subscription_defaults', $defaults);
        }
    }

    public function down()
    {
        foreach ($this->columns() as $table_name => $columns) {
            if (!Schema::hasTable($table_name)) {
                continue;
            }
            foreach ($columns as $column => $add) {
                if (!Schema::hasColumn($table_name, $column)) {
                    Schema::table($table_name, function (Blueprint $table) use ($add) {
                        $add($table);
                    });
                }
            }
        }
    }
}

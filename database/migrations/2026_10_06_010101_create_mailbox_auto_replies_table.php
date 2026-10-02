<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mailboxes' auto replies in other languages. Versions kept by the
 * multilingualautoreply module are copied (its Chinese one to both
 * Simplified and Traditional Chinese) and the module is switched off.
 */
class CreateMailboxAutoRepliesTable extends Migration
{
    public function up()
    {
        Schema::create('mailbox_auto_replies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id');
            $table->string('language', 16);
            $table->boolean('enabled')->default(false);
            $table->string('subject', 255)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'language']);
        });

        if (Schema::hasTable('multilingualautoreply_templates')) {
            self::copyVersions(DB::table('multilingualautoreply_templates')->get());
        }
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('alias', 'multilingualautoreply')->update(['active' => false]);
        }
    }

    /**
     * Copy versions from the module's table.
     */
    public static function copyVersions($rows)
    {
        $languages = ['zh' => ['zh-Hans', 'zh-Hant'], 'ja' => ['ja'], 'ko' => ['ko']];
        foreach ($rows as $row) {
            foreach ($languages[$row->language] ?? [] as $language) {
                DB::table('mailbox_auto_replies')->insertOrIgnore([
                    'mailbox_id' => $row->mailbox_id,
                    'language'   => $language,
                    'enabled'    => (bool) $row->enabled,
                    'subject'    => $row->subject,
                    'message'    => $row->message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('mailbox_auto_replies');
    }
}

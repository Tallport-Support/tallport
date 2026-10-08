<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep a user's daily draft count after its conversation is deleted, without
 * keeping the conversation link or the draft's contents.
 */
class AllowUnlinkedAiDraftJobs extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_draft_jobs', function (Blueprint $table) {
            $table->unsignedInteger('conversation_id')->nullable()->change();
        });
    }

    public function down()
    {
        \DB::table('aiassistant_draft_jobs')->whereNull('conversation_id')->delete();

        Schema::table('aiassistant_draft_jobs', function (Blueprint $table) {
            $table->unsignedInteger('conversation_id')->change();
        });
    }
}

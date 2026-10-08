<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One key per send attempt, so a repeated composer request cannot create a
 * second reply or conversation.
 */
class AddSubmissionKeyToThreads extends Migration
{
    public function up()
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->char('submission_key', 36)->nullable()->unique();
        });
    }

    public function down()
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn('submission_key');
        });
    }
}

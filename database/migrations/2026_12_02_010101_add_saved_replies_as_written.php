<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved reply sent as written in a translated conversation (Don't Translate): one already in
 * several languages.
 */
class AddSavedRepliesAsWritten extends Migration
{
    public function up()
    {
        Schema::table('saved_replies', function (Blueprint $table) {
            $table->boolean('as_written')->default(false);
        });
    }

    public function down()
    {
        Schema::table('saved_replies', function (Blueprint $table) {
            $table->dropColumn('as_written');
        });
    }
}

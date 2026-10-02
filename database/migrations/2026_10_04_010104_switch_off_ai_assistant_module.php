<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Assistant is part of Tallport now: an aiassistant module, which
 * would summarize and translate a second time, is switched off. Its tables
 * and settings are the ones Tallport uses.
 */
class SwitchOffAiAssistantModule extends Migration
{
    public function up()
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('alias', 'aiassistant')->update(['active' => false]);
        }
    }

    public function down()
    {
    }
}

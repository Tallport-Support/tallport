<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System » Status no longer shows where PCRE's JIT is off, so tallport:receive
 * doesn't record it.
 */
class RemoveReceivePcreJitOffOption extends Migration
{
    public function up()
    {
        DB::table('options')->where('name', 'receive_pcre_jit_off')->delete();
    }

    public function down()
    {
    }
}

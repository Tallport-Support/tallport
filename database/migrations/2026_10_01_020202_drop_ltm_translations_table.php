<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The translation manager (Manage » Translate) is gone; translations ship
 * complete with every release. Older installations still have its table.
 */
class DropLtmTranslationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('ltm_translations');
    }
}

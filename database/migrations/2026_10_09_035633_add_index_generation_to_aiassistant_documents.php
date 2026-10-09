<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndexGenerationToAiassistantDocuments extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_documents', function (Blueprint $table) {
            $table->string('content_generation', 36)->nullable();
            $table->string('embedding_fingerprint', 64)->nullable();
        });
    }

    public function down()
    {
        Schema::table('aiassistant_documents', function (Blueprint $table) {
            $table->dropColumn(['content_generation', 'embedding_fingerprint']);
        });
    }
}

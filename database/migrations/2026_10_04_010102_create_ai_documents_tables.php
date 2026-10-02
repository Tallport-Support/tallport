<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant documentation: documents, their chunks with embeddings, and
 * the keys websites use to push documents for a mailbox. Tables that
 * already exist (an earlier AI Assistant) are kept as they are.
 */
class CreateAiDocumentsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('aiassistant_documents')) {
            Schema::create('aiassistant_documents', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mailbox_id')->index();
                $table->string('title');
                // url: fetched from source_url (as Markdown); api: pushed.
                $table->string('source_type', 50)->default('url')->index();
                $table->string('source_url', 2048);
                $table->string('source_identifier')->nullable()->index();
                $table->string('canonical_locale', 10)->default('en');
                $table->longText('localized_urls')->nullable();
                $table->longText('content')->nullable();
                $table->string('content_hash', 64)->nullable()->index();
                $table->string('status', 50)->default('pending')->index();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamp('last_indexed_at')->nullable()->index();
                $table->text('last_error')->nullable();
                $table->longText('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('aiassistant_document_chunks')) {
            Schema::create('aiassistant_document_chunks', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('document_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->longText('content');
                $table->string('content_hash', 64)->nullable()->index();
                $table->unsignedInteger('token_count')->nullable();
                // JSON array of floats.
                $table->longText('embedding')->nullable();
                $table->string('embedding_model')->nullable()->index();
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['document_id', 'chunk_index']);
            });
        }

        if (!Schema::hasTable('aiassistant_mailbox_api_keys')) {
            Schema::create('aiassistant_mailbox_api_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mailbox_id')->unique();
                $table->string('key_hash', 64)->unique();
                $table->string('key_preview', 32)->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('aiassistant_mailbox_api_keys');
        Schema::dropIfExists('aiassistant_document_chunks');
        Schema::dropIfExists('aiassistant_documents');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The REST API's per-user keys and webhooks (App\Api). Tables that exist
 * already are kept, with their keys and webhooks.
 */
class CreateApiTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('name', 255);
                // SHA-256 of the key: the key itself is shown once.
                $table->string('key_hash', 64)->unique();
                // The key's last characters, to recognise it.
                $table->string('token_preview', 8);
                // 1: read, 2: read and write.
                $table->unsignedTinyInteger('ability')->default(1);
                // JSON mailbox IDs; null: every mailbox of the user.
                $table->text('mailboxes')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('webhooks')) {
            Schema::create('webhooks', function (Blueprint $table) {
                $table->increments('id');
                $table->string('url', 255);
                // JSON event names.
                $table->text('events')->nullable();
                $table->timestamp('last_run_time')->nullable();
                $table->text('last_run_error')->nullable();
                // JSON mailbox IDs; empty: every mailbox.
                $table->text('mailboxes')->nullable();
            });
        } elseif (!Schema::hasColumn('webhooks', 'mailboxes')) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->text('mailboxes')->nullable();
            });
        }

        if (!Schema::hasTable('webhook_logs')) {
            // Deliveries that failed, retried until they succeed or give up.
            Schema::create('webhook_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('webhook_id')->index();
                $table->integer('status_code');
                $table->text('error');
                $table->string('event', 255);
                $table->longText('data')->nullable();
                $table->unsignedTinyInteger('attempts')->default(1);
                $table->boolean('finished')->default(false)->index();
                $table->timestamps();
            });
        }

        // Its keys, webhooks and failed deliveries are kept.
        try {
            if (\App\Module::isActive('apiwebhooks')) {
                \App\Module::setActive('apiwebhooks', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_keys');
    }
}

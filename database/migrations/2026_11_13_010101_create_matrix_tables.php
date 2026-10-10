<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matrix_mailboxes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->index();
            $table->unsignedInteger('active_mailbox_id')->nullable()->unique();
            $table->string('homeserver', 1024);
            $table->string('user_id');
            $table->char('user_hash', 64)->unique();
            $table->string('device_id');
            $table->string('status', 32)->default('verification');
            $table->longText('credentials');
            $table->text('sync_token')->nullable();
            $table->unsignedBigInteger('sync_sequence')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
        });
        Schema::create('matrix_rooms', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('matrix_mailbox_id');
            $table->string('room_id');
            $table->char('room_hash', 64);
            $table->string('customer_user_id')->nullable();
            $table->char('customer_hash', 64)->nullable()->index();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('conversation_id')->nullable();
            $table->longText('state');
            $table->timestamps();
            $table->unique(['matrix_mailbox_id', 'room_hash']);
            $table->foreign('matrix_mailbox_id')->references('id')->on('matrix_mailboxes')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
        Schema::create('matrix_crypto', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('matrix_mailbox_id');
            $table->string('kind', 32);
            $table->char('key_hash', 64);
            $table->longText('value');
            $table->timestamps();
            $table->unique(['matrix_mailbox_id', 'kind', 'key_hash'], 'matrix_crypto_identity_key');
            $table->foreign('matrix_mailbox_id')->references('id')->on('matrix_mailboxes')->cascadeOnDelete();
        });
        Schema::create('matrix_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('matrix_mailbox_id');
            $table->string('kind', 32);
            $table->char('local_key', 64);
            $table->string('room_id')->nullable();
            $table->text('remote_id')->nullable();
            $table->char('remote_hash', 64)->nullable();
            $table->char('replay_hash', 64)->nullable();
            $table->string('transaction_id', 64)->nullable();
            $table->unsignedInteger('thread_id')->nullable()->index();
            $table->unsignedInteger('conversation_id')->nullable();
            $table->longText('payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('sort_order', 64)->default('');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->unique(['matrix_mailbox_id', 'local_key']);
            $table->unique(['matrix_mailbox_id', 'remote_hash']);
            $table->unique(['matrix_mailbox_id', 'replay_hash']);
            $table->index(['matrix_mailbox_id', 'kind', 'status']);
            $table->foreign('matrix_mailbox_id')->references('id')->on('matrix_mailboxes')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('threads')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_events');
        Schema::dropIfExists('matrix_crypto');
        Schema::dropIfExists('matrix_rooms');
        Schema::dropIfExists('matrix_mailboxes');
    }
};

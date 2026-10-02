<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nostr: each mailbox's identity (nostr_mailboxes, with retired keys in
 * nostr_mailbox_keys), customers' public keys and every message wrap
 * received or sent (nostr_events). Tables that exist already (the nostr
 * module's) are kept; the module is switched off.
 */
class CreateNostrTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('nostr_mailboxes')) {
            Schema::create('nostr_mailboxes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mailbox_id')->unique();
                $table->boolean('enabled')->default(false);
                // Hex public key (x-only, 64 chars).
                $table->string('pubkey', 64)->nullable()->index();
                // Private key encrypted with the application key.
                $table->text('private_key')->nullable();
                // JSON arrays of relay URLs.
                $table->text('inbox_relays')->nullable();
                $table->text('announce_relays')->nullable();
                // Kind 0 profile.
                $table->string('profile_name', 255)->nullable();
                $table->text('profile_about')->nullable();
                $table->string('profile_picture', 1024)->nullable();
                // One-time auto reply for new conversations.
                $table->boolean('auto_reply_enabled')->default(false);
                $table->text('auto_reply_text')->nullable();
                // Days of silence after which a new conversation is started.
                $table->unsignedSmallInteger('reopen_days')->default(30);
                $table->dateTime('last_announced_at')->nullable();
                $table->dateTime('last_event_at')->nullable();
                $table->timestamps();
                $table->dateTime('key_created_at')->nullable();
                // NIP-05 address (name@domain), on any domain.
                $table->string('nip05', 255)->nullable();
            });
        }

        if (!Schema::hasTable('nostr_mailbox_keys')) {
            // Keys are never destroyed: a replaced key is retired here and keeps working.
            Schema::create('nostr_mailbox_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mailbox_id')->index();
                $table->string('pubkey', 64)->unique();
                $table->text('private_key');
                $table->dateTime('key_created_at')->nullable();
                $table->dateTime('retired_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('nostr_customer_keys')) {
            // A customer can have several keys (personal client, one per app
            // install...); the first is also in customer_channel.
            Schema::create('nostr_customer_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id')->index();
                $table->string('pubkey', 64)->unique();
                $table->string('label', 255)->nullable();
                // auto (first message), manual (agent), api.
                $table->string('source', 16)->default('auto');
                // Cached kind 0 profile (JSON).
                $table->text('profile')->nullable();
                // Cached kind 10050 DM relay list (JSON) and when it was fetched.
                $table->text('dm_relays')->nullable();
                $table->dateTime('dm_relays_fetched_at')->nullable();
                $table->dateTime('first_seen_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('nostr_events')) {
            // Every gift wrap received or sent: de-duplication, the key to reply
            // to, and threading replies with "e" tags.
            Schema::create('nostr_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mailbox_id')->index();
                // 1 = incoming, 2 = outgoing.
                $table->unsignedTinyInteger('direction');
                // Kind 1059 event ID.
                $table->string('wrap_id', 64)->nullable()->unique();
                // Kind 14/15 rumor ID: a message becomes one thread.
                $table->string('rumor_id', 64)->nullable()->unique();
                // The other party's public key.
                $table->string('pubkey', 64)->index();
                $table->unsignedSmallInteger('kind')->default(14);
                $table->unsignedInteger('conversation_id')->nullable()->index();
                $table->unsignedInteger('thread_id')->nullable()->index();
                // Relay the wrap arrived on, or JSON publish results for outgoing wraps.
                $table->string('relay', 255)->nullable();
                $table->text('relays')->nullable();
                // 1 = ok, 2 = failed.
                $table->unsignedTinyInteger('status')->default(1);
                $table->text('error')->nullable();
                // created_at of the rumor (real time of the message).
                $table->dateTime('event_created_at')->nullable();
                $table->timestamps();
                // Which of the mailbox's keys the message was addressed to (or sent from).
                $table->string('mailbox_pubkey', 64)->nullable()->index();
            });
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('alias', 'nostr')->update(['active' => false]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('nostr_events');
        Schema::dropIfExists('nostr_customer_keys');
        Schema::dropIfExists('nostr_mailbox_keys');
        Schema::dropIfExists('nostr_mailboxes');
    }
}

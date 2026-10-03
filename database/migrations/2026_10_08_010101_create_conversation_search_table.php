<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The search index: a conversation's text in one row, with MariaDB
 * full-text indexes (App\Search). Filled by tallport:search-index.
 *
 * The FastSearch module's full-text indexes and customers.full_name are
 * removed: Tallport's search replaces it, and they slow down every write.
 */
class CreateConversationSearchTable extends Migration
{
    public function up()
    {
        $fulltext = self::supportsFulltext();
        if ($fulltext) {
            // Index every word: with MariaDB's stopwords, "+about" finds nothing.
            // A rebuild of the table (TRUNCATE, ALTER) needs this too.
            DB::statement('SET SESSION innodb_ft_enable_stopword = 0');
        }

        Schema::create('conversation_search', function (Blueprint $table) use ($fulltext) {
            $table->unsignedInteger('conversation_id')->primary();
            $table->text('subject')->nullable();
            // Who wrote: customers (names, addresses, phones, Telegram and Nostr
            // names) and agents.
            $table->text('people')->nullable();
            // To, Cc and Bcc addresses.
            $table->text('recipients')->nullable();
            // Messages and notes as plain text, and attachment names.
            $table->mediumText('content')->nullable();
            // Null: to be indexed again.
            $table->dateTime('indexed_at')->nullable()->index();

            if ($fulltext) {
                $table->fullText(['subject', 'people', 'recipients', 'content'], 'conversation_search_all');
                $table->fullText('subject', 'conversation_search_subject');
                $table->fullText('people', 'conversation_search_people');
                $table->fullText('recipients', 'conversation_search_recipients');
            }
        });

        if ($fulltext) {
            DB::statement('SET SESSION innodb_ft_enable_stopword = 1');
            self::removeFastSearch();
        }
    }

    public function down()
    {
        Schema::dropIfExists('conversation_search');
    }

    public static function supportsFulltext()
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb']);
    }

    /**
     * Switch off the FastSearch module and drop what it added.
     */
    public static function removeFastSearch()
    {
        try {
            if (\App\Module::isActive('fastsearch')) {
                \App\Module::setActive('fastsearch', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }

        foreach (['conversations' => 'fulltext_subject_customer_email', 'threads' => 'fulltext_body'] as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
        if (Schema::hasColumn('customers', 'full_name')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('full_name');
            });
        }
    }
}

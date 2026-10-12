<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markdown as written (App\Misc\Markdown): the text people edit. The HTML
 * column next to it is rendered from it for emails, display and modules;
 * without Markdown, the HTML is the text (written in rich text).
 */
class AddMarkdownColumns extends Migration
{
    const COLUMNS = [
        'threads'             => 'body_markdown',
        'saved_replies'       => 'text_markdown',
        'mailboxes'           => ['signature_markdown', 'auto_reply_message_markdown'],
        'mailbox_auto_replies' => 'message_markdown',
        'kb_articles'         => 'body_markdown',
    ];

    public function up()
    {
        foreach (self::COLUMNS as $table_name => $columns) {
            Schema::table($table_name, function (Blueprint $table) use ($columns) {
                foreach ((array) $columns as $column) {
                    $table->longText($column)->nullable();
                }
            });
        }
        // Replies written in Markdown (Preferences).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('markdown_replies')->default(false);
        });
    }

    public function down()
    {
        foreach (self::COLUMNS as $table_name => $columns) {
            Schema::table($table_name, function (Blueprint $table) use ($columns) {
                $table->dropColumn((array) $columns);
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('markdown_replies');
        });
    }
}

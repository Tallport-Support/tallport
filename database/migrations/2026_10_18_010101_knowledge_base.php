<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base (App\KbArticle): articles for agents, of a mailbox or
 * of all mailboxes (no mailbox_id). Modules adding a knowledge base are
 * switched off.
 */
class KnowledgeBase extends Migration
{
    public function up()
    {
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->nullable()->index();
            $table->string('category', 100)->nullable()->index();
            $table->string('title', 191);
            $table->longText('body')->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->unsignedInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        try {
            if (\App\Module::isActive('knowledgebase')) {
                \App\Module::setActive('knowledgebase', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
        Schema::dropIfExists('kb_articles');
    }
}

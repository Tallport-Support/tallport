<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved replies (App\SavedReply). A saved_replies table that exists
 * already is kept, with its saved replies; missing columns are added. A
 * module adding the same is switched off.
 */
class CreateSavedRepliesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('saved_replies')) {
            Schema::create('saved_replies', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('mailbox_id');
                $table->string('name', 75);
                $table->longText('text')->nullable();
                // Who made it.
                $table->integer('user_id')->nullable();
                $table->timestamps();
                $table->integer('sort_order')->default(1);
                // Under another saved reply (a category).
                $table->unsignedInteger('parent_saved_reply_id')->nullable();
                // JSON attachment IDs.
                $table->text('attachments')->nullable();
                // Available in every mailbox.
                $table->boolean('global')->default(false);
                // The mailbox's default reply template.
                $table->boolean('auto_load')->default(false);

                $table->index(['mailbox_id', 'sort_order']);
                $table->index('global');
                $table->index(['auto_load', 'mailbox_id', 'global']);
            });
        } else {
            $columns = [
                'sort_order'            => function ($table) {
                    $table->integer('sort_order')->default(1);
                },
                'parent_saved_reply_id' => function ($table) {
                    $table->unsignedInteger('parent_saved_reply_id')->nullable();
                },
                'attachments'           => function ($table) {
                    $table->text('attachments')->nullable();
                },
                'global'                => function ($table) {
                    $table->boolean('global')->default(false);
                },
                'auto_load'             => function ($table) {
                    $table->boolean('auto_load')->default(false);
                },
            ];
            foreach ($columns as $column => $add) {
                if (!Schema::hasColumn('saved_replies', $column)) {
                    Schema::table('saved_replies', $add);
                }
            }
        }

        try {
            if (\App\Module::isActive('savedreplies')) {
                \App\Module::setActive('savedreplies', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
        Schema::dropIfExists('saved_replies');
    }
}

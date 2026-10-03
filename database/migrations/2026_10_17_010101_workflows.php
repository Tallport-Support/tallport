<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows (App\Workflow, App\Workflows): conditions and actions run on
 * conversations. Tables a module made for the same are kept as they are;
 * workflows without a mailbox apply to all mailboxes. The module is switched
 * off.
 */
class Workflows extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflows')) {
            Schema::create('workflows', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('mailbox_id')->nullable();
                $table->string('name', 75);
                $table->unsignedTinyInteger('type')->default(1);
                $table->boolean('apply_to_prev')->default(false);
                $table->boolean('complete')->default(false);
                $table->boolean('active')->default(false);
                $table->text('conditions')->nullable();
                $table->text('actions')->nullable();
                $table->integer('sort_order')->default(1);
                $table->timestamps();
                $table->integer('max_executions')->default(1);

                $table->index(['mailbox_id', 'active', 'type', 'sort_order']);
            });
        } else {
            Schema::table('workflows', function (Blueprint $table) {
                $table->integer('mailbox_id')->nullable()->change();
            });
            if (!Schema::hasColumn('workflows', 'max_executions')) {
                Schema::table('workflows', function (Blueprint $table) {
                    $table->integer('max_executions')->default(1);
                });
            }
        }

        // Which workflows ran on a conversation, how often.
        if (!Schema::hasTable('conversation_workflow')) {
            Schema::create('conversation_workflow', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('conversation_id');
                $table->integer('workflow_id');
                $table->integer('counter')->default(1);

                $table->unique(['conversation_id', 'workflow_id']);
                $table->index('workflow_id');
            });
        } elseif (!Schema::hasColumn('conversation_workflow', 'counter')) {
            Schema::table('conversation_workflow', function (Blueprint $table) {
                $table->integer('counter')->default(1);
            });
        }

        try {
            if (\App\Module::isActive('workflows')) {
                \App\Module::setActive('workflows', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
    }
}

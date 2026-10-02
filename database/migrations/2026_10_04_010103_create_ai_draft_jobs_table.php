<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant reply drafts (queued, then shown to the user who asked), and
 * customer context secrets stored encrypted.
 */
class CreateAiDraftJobsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('aiassistant_draft_jobs')) {
            Schema::create('aiassistant_draft_jobs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('conversation_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->string('status', 50)->default('pending')->index();
                $table->string('locale', 10)->nullable();
                $table->unsignedTinyInteger('document_limit')->default(0);
                $table->longText('result')->nullable();
                $table->string('error_type', 100)->nullable();
                $table->longText('error_message')->nullable();
                $table->longText('error_detail')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('created_at');
            });
        }

        $secrets = \Option::get('aiassistant.customer_context_secret_key', []);
        if (is_array($secrets) && $secrets) {
            \Option::set('aiassistant.customer_context_secret_key', self::encryptSecrets($secrets));
        }
    }

    /**
     * Secrets stored as plain text, encrypted.
     */
    public static function encryptSecrets(array $secrets)
    {
        foreach ($secrets as $mailbox_id => $secret) {
            if ((string) $secret !== '' && \Helper::decrypt($secret) === '') {
                $secrets[$mailbox_id] = encrypt((string) $secret);
            }
        }

        return $secrets;
    }

    public function down()
    {
        Schema::dropIfExists('aiassistant_draft_jobs');
    }
}

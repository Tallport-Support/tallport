<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant: summaries (conversations) and translations (threads) by
 * language, and each user's language and daily draft limit. Columns that
 * already exist (an earlier AI Assistant) are kept, and their contents put
 * in the by-language form.
 */
class AddAiAssistantColumns extends Migration
{
    public function up()
    {
        foreach (['threads', 'conversations'] as $table_name) {
            if (!Schema::hasColumn($table_name, 'ai_assistant')) {
                Schema::table($table_name, function (Blueprint $table) {
                    $table->text('ai_assistant')->nullable();
                    $table->timestamp('ai_assistant_updated_at')->nullable()->index();
                });
            }
        }

        Schema::table('users', function (Blueprint $table) {
            // Summaries and translations in this language (else the mailbox's).
            $table->string('ai_language', 20)->nullable();
            // Drafts per day (else the installation's limit); 0: none.
            $table->unsignedSmallInteger('ai_drafts_per_day')->nullable();
        });

        $language = \Option::get('aiassistant.translation_language', 'en') ?: 'en';

        DB::table('conversations')->whereNotNull('ai_assistant')->orderBy('id')->each(function ($row) {
            if (($json = self::summaries($row->ai_assistant)) !== null) {
                DB::table('conversations')->where('id', $row->id)->update(['ai_assistant' => $json]);
            }
        });
        DB::table('threads')->whereNotNull('ai_assistant_updated_at')->orderBy('id')->each(function ($row) use ($language) {
            if (($json = self::translations($row->ai_assistant, $language)) !== null) {
                DB::table('threads')->where('id', $row->id)->update(['ai_assistant' => $json]);
            }
        });
    }

    /**
     * A conversation's {"one_liner", "summary"} as {"summaries": {"en": {...}}}
     * (the earlier assistant summarized in English); null: nothing to change.
     */
    public static function summaries($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['one_liner']) || isset($data['summaries'])) {
            return null;
        }

        return json_encode(['summaries' => ['en' => [
            'one_liner' => (string) $data['one_liner'],
            'summary'   => (string) ($data['summary'] ?? ''),
        ]]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * A processed thread's {"translation"} as {"translations": {language: "..."}};
     * processed without a translation: the thread is in that language.
     */
    public static function translations($json, $language)
    {
        $data = json_decode((string) $json, true);
        if (is_array($data) && isset($data['translations'])) {
            return null;
        }

        return json_encode(!empty($data['translation'])
            ? ['translations' => [$language => (string) $data['translation']]]
            : ['language' => $language, 'translations' => []], JSON_UNESCAPED_UNICODE);
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_language', 'ai_drafts_per_day']);
        });
    }
}

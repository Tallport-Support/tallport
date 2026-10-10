<?php

use App\Ai\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Languages, the same for agents and customers: their own (an agent's is the
 * language of their interface) and those they need no translation of. A
 * user's AI language becomes one of theirs; the language of a chat (chosen by
 * an agent, else detected) becomes its customer's.
 */
class CustomerAndUserLanguages extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'languages')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('languages')->nullable();
            });
        }
        if (!Schema::hasColumn('customers', 'language')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('language', 20)->nullable();
                $table->text('languages')->nullable();
            });
        }

        if (Schema::hasColumn('users', 'ai_language')) {
            DB::table('users')->whereNotNull('ai_language')->orderBy('id')->each(function ($row) {
                if (($languages = self::userLanguages($row->ai_language, $row->locale)) !== null) {
                    DB::table('users')->where('id', $row->id)->update(['languages' => $languages]);
                }
            });
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('ai_language');
            });
        }

        self::customerLanguages();
    }

    /**
     * A user's AI language other than their own: one they read (users.languages, JSON); null:
     * nothing to keep.
     */
    public static function userLanguages($ai_language, $locale)
    {
        if (!Settings::isLanguage($ai_language) || $ai_language === Settings::fromLocale($locale ?: \Helper::getRealAppLocale())) {
            return null;
        }

        return json_encode([$ai_language]);
    }

    /**
     * Customers without a language get their chats' (conversations.ai_assistant): an agent's
     * choice first, then a detected one; the newest conversation's of each.
     */
    public static function customerLanguages()
    {
        foreach (['user', 'detected'] as $by) {
            DB::table('conversations')->whereNotNull('customer_id')->where('ai_assistant', 'like', '%"language_by"%')
                ->orderBy('id', 'desc')->each(function ($row) use ($by) {
                    $data = json_decode((string) $row->ai_assistant, true);
                    $language = $data['language'] ?? null;
                    if (($data['language_by'] ?? null) === $by && Settings::isLanguage($language)) {
                        DB::table('customers')->where('id', $row->customer_id)->whereNull('language')->update(['language' => $language]);
                    }
                });
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ai_language', 20)->nullable();
            $table->dropColumn('languages');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['language', 'languages']);
        });
    }
}

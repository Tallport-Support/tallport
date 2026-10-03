<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyboard shortcuts, Send & Close and no-reply warnings are built in:
 * users can turn shortcuts off (null: on). Modules adding the same are
 * switched off; no-reply patterns kept in NOREPLY_EMAILS_CUSTOM (.env)
 * become the option noreply_emails.
 */
class AddKeyboardShortcutsColumnToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'keyboard_shortcuts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('keyboard_shortcuts')->nullable();
            });
        }

        self::importNoreplyPatterns(base_path('.env'));

        try {
            foreach (['sendclose', 'noreply', 'keyboardshortcuts'] as $alias) {
                if (\App\Module::isActive($alias)) {
                    \App\Module::setActive($alias, false);
                }
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('keyboard_shortcuts');
        });
    }

    /**
     * NOREPLY_EMAILS_CUSTOM (a JSON list) in the environment file → the
     * option, unless the option is set already.
     */
    public static function importNoreplyPatterns($env_file)
    {
        if (\Option::get(\App\Misc\Noreply::OPTION) || !is_file($env_file)) {
            return;
        }
        try {
            $env = \Dotenv\Dotenv::parse((string) file_get_contents($env_file));
        } catch (\Throwable $e) {
            return;
        }
        $patterns = json_decode($env['NOREPLY_EMAILS_CUSTOM'] ?? '', true);
        if (is_array($patterns) && $patterns) {
            \Option::set(\App\Misc\Noreply::OPTION, implode("\n", \App\Misc\Noreply::normalize(implode("\n", $patterns))));
        }
    }
}

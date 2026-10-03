<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Embedding images in emails, blocking images from other servers and the
 * attachment features are built in: modules adding the same are switched
 * off. Attachment reminder words kept in
 * EXTENDEDATTACHMENTS_REMINDER_PHRASES (.env, base64) become an option.
 */
class ImagesAndAttachments extends Migration
{
    public function up()
    {
        self::importReminderPhrases(base_path('.env'));

        try {
            foreach (['embedimages', 'blockexternalimages', 'extendedattachments'] as $alias) {
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
    }

    public static function importReminderPhrases($env_file)
    {
        $option = \App\Http\Controllers\AttachmentsController::REMINDER_OPTION;
        if (\Option::get($option, null) !== null || !is_file($env_file)) {
            return;
        }
        try {
            $env = \Dotenv\Dotenv::parse((string) file_get_contents($env_file));
        } catch (\Throwable $e) {
            return;
        }
        if (!isset($env['EXTENDEDATTACHMENTS_REMINDER_PHRASES'])) {
            return;
        }
        $phrases = base64_decode($env['EXTENDEDATTACHMENTS_REMINDER_PHRASES'], true);
        if ($phrases !== false) {
            \Option::set($option, trim(strip_tags($phrases)));
        }
    }
}

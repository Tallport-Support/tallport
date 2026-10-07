<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Settings from the settings pages that FreeScout kept in .env (timezone,
 * locale, user permissions...) become options (App\Misc\DatabaseSettings):
 * the value in .env is saved as the option and its line is removed. When
 * .env can't be written the line stays, and keeps overriding the option.
 */
class MoveEnvSettingsToOptions extends Migration
{
    /**
     * Comments the installer wrote above APP_TIMEZONE and APP_LOCALE.
     */
    const INSTALLER_COMMENTS = [
        '# Timezones: https://github.com/freescout-helpdesk/freescout/wiki/PHP-Timezones',
        '# Comment it to use default timezone from php.ini',
        '# Default language',
    ];

    public function up()
    {
        // Tests call moveToOptions() with a file of their own.
        if (app()->runningUnitTests()) {
            return;
        }
        if (self::moveToOptions(app()->environmentFilePath())) {
            // The cached config has the removed variables.
            \Helper::clearCache(['--doNotGenerateVars' => true]);
        }
    }

    public function down()
    {
    }

    /**
     * @return bool Whether lines were removed from the file.
     */
    public static function moveToOptions($env_file)
    {
        if (!is_file($env_file)) {
            return false;
        }
        try {
            $env = \Dotenv\Dotenv::parse((string) file_get_contents($env_file));
        } catch (\Throwable $e) {
            \Log::warning('Settings in .env not moved to the database: .env could not be read ('.$e->getMessage().')');

            return false;
        }

        $removed = false;
        foreach (\App\Misc\DatabaseSettings::SETTINGS as $option => $setting) {
            if (!array_key_exists($setting['env'], $env)) {
                continue;
            }
            // What .env has is what was in effect (an option of the same name
            // is older: FreeScout moved user_permissions to .env, keeping it).
            \Option::set($option, \App\Misc\DatabaseSettings::optionValueFromEnv($option, $env[$setting['env']]));

            if (!is_writable($env_file) || !\App\Misc\EnvFile::removeVar($env_file, $setting['env'])) {
                \Log::warning($setting['env'].' stays in .env, which could not be written: it overrides the setting saved in the database');
                continue;
            }
            // Gone from this process too, for the config cached after this.
            putenv($setting['env']);
            unset($_ENV[$setting['env']], $_SERVER[$setting['env']]);
            $removed = true;
        }

        if ($removed) {
            $contents = file_get_contents($env_file);
            foreach (self::INSTALLER_COMMENTS as $comment) {
                $contents = preg_replace('/^'.preg_quote($comment, '/').'\r?\n/m', '', $contents);
            }
            file_put_contents($env_file, $contents);
        }

        return $removed;
    }
}

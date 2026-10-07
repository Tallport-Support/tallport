<?php

namespace App\Misc;

/**
 * Settings from the settings pages that FreeScout kept in .env. They are
 * options (App\Option) now; config() holds them as before, so code and modules
 * reading config('app.timezone') and the like see the saved value.
 *
 * A variable set in the environment (.env) still wins: the setting is then
 * shown as locked on its settings page and isn't saved.
 */
class DatabaseSettings
{
    /**
     * Option => the environment variable and the config keys it fills.
     */
    const SETTINGS = [
        'fetch_schedule'     => ['env' => 'APP_FETCH_SCHEDULE', 'config' => ['app.fetch_schedule']],
        'custom_number'      => ['env' => 'APP_CUSTOM_NUMBER', 'config' => ['app.custom_number']],
        'max_message_size'   => ['env' => 'APP_MAX_MESSAGE_SIZE', 'config' => ['app.max_message_size']],
        'email_conv_history' => ['env' => 'APP_EMAIL_CONV_HISTORY', 'config' => ['app.email_conv_history']],
        'email_user_history' => ['env' => 'APP_EMAIL_USER_HISTORY', 'config' => ['app.email_user_history']],
        'locale'             => ['env' => 'APP_LOCALE', 'config' => ['app.locale', 'app.real_locale']],
        'timezone'           => ['env' => 'APP_TIMEZONE', 'config' => ['app.timezone']],
        // An array of permission IDs; config has it as base64 of the JSON, as .env did.
        'user_permissions'   => ['env' => 'APP_USER_PERMISSIONS', 'config' => ['app.user_permissions']],
        'alert_logs'         => ['env' => 'APP_ALERT_LOGS', 'config' => ['app.alert_logs']],
        'alert_logs_period'  => ['env' => 'APP_ALERT_LOGS_PERIOD', 'config' => ['app.alert_logs_period']],
        'api.cors_hosts'     => ['env' => 'APIWEBHOOKS_CORS_HOSTS', 'config' => ['api.cors_hosts']],
    ];

    /**
     * Put the saved settings into config, unless the environment sets them,
     * and redo what Laravel did with the old values at bootstrap (timezone,
     * locale). Before the database exists (installing) nothing is saved yet.
     */
    public static function apply()
    {
        try {
            $options = \App\Option::whereIn('name', array_keys(self::SETTINGS))->pluck('value', 'name')->all();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($options as $name => $value) {
            if (self::lockedByEnv($name)) {
                continue;
            }
            $value = self::configValue($name, \App\Option::maybeUnserialize($value));
            foreach (self::SETTINGS[$name]['config'] as $key) {
                config([$key => $value]);
            }

            if ($name == 'timezone') {
                try {
                    new \DateTimeZone((string) $value);
                    date_default_timezone_set($value);
                } catch (\Throwable $e) {
                    // Not a timezone: PHP's stays.
                }
            } elseif ($name == 'locale' && $value) {
                app()->setLocale($value);
            } elseif ($name == 'api.cors_hosts') {
                config(['cors.allowed_origins' => self::corsOrigins($value)]);
            }
        }
    }

    /**
     * Whether the environment (.env) sets the setting, which then can't be changed in the UI.
     */
    public static function lockedByEnv($option)
    {
        return isset(self::SETTINGS[$option]) && in_array(self::SETTINGS[$option]['env'], (array) config('app.settings_in_env'));
    }

    /**
     * The note shown at a setting the environment sets, null when it doesn't.
     */
    public static function lockedNote($option)
    {
        return self::lockedByEnv($option) ? __('Set by :name in the .env file', ['name' => self::SETTINGS[$option]['env']]) : null;
    }

    /**
     * The environment variables of the settings that the environment sets
     * (config app.settings_in_env, which a cached config keeps).
     */
    public static function setInEnvironment()
    {
        return array_values(array_filter(array_column(self::SETTINGS, 'env'), function ($name) {
            return env($name) !== null;
        }));
    }

    /**
     * A value from the settings form as it is saved.
     */
    public static function optionValue($option, $value)
    {
        if ($option == 'user_permissions') {
            return array_values(array_filter((array) $value, 'strlen'));
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }

    /**
     * A value from .env as it is saved.
     */
    public static function optionValueFromEnv($option, $value)
    {
        if ($option == 'user_permissions') {
            $permissions = json_decode((string) base64_decode((string) $value), true);

            return is_array($permissions) ? $permissions : [];
        }

        return (string) $value;
    }

    /**
     * A saved value as config has it: what env() made of it before.
     */
    public static function configValue($option, $value)
    {
        if ($option == 'user_permissions') {
            return is_array($value) ? base64_encode(json_encode(array_values($value))) : '';
        }
        if (!is_string($value)) {
            return $value;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }

    /**
     * The CORS origins for the allowed hosts (comma separated), as config/cors.php has them.
     */
    public static function corsOrigins($hosts)
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $hosts))));
    }
}

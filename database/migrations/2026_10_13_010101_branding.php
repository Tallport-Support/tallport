<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Branding is built in (Settings » Branding, App\Misc\Branding): branding
 * settings kept in .env (CUSTOMIZATION_*) become options; modules adding
 * the same are switched off. When "Powered by" in widgets was hidden by a
 * module, it stays hidden.
 */
class Branding extends Migration
{
    /**
     * .env variable => [option, base64].
     */
    const IMPORT = [
        'CUSTOMIZATION_LOGO'                  => ['branding.logo', false],
        'CUSTOMIZATION_BANNER'                => ['branding.banner', false],
        'CUSTOMIZATION_FAVICON'               => ['branding.favicon', false],
        'CUSTOMIZATION_HEADER'                => ['branding.header_color', false],
        'CUSTOMIZATION_TITLE'                 => ['branding.title', false],
        'CUSTOMIZATION_FOOTER'                => ['branding.footer', false],
        'CUSTOMIZATION_CSS'                   => ['branding.css', true],
        'CUSTOMIZATION_CUSTOMER_EMAIL_CSS'    => ['branding.email_css', true],
        'CUSTOMIZATION_CUSTOMER_EMAIL_HEADER' => ['branding.email_header', true],
        'CUSTOMIZATION_CUSTOMER_EMAIL_FOOTER' => ['branding.email_footer', true],
    ];

    public function up()
    {
        self::importSettings(base_path('.env'));

        try {
            if (\App\Module::isActive('whitelabel')) {
                \Option::set('branding.widget_powered_by', false);
            }
            foreach (['customization', 'whitelabel'] as $alias) {
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

    public static function importSettings($env_file)
    {
        if (!is_file($env_file)) {
            return;
        }
        try {
            $env = \Dotenv\Dotenv::parse((string) file_get_contents($env_file));
        } catch (\Throwable $e) {
            return;
        }
        foreach (self::IMPORT as $variable => [$option, $base64]) {
            $value = (string) ($env[$variable] ?? '');
            if ($value === '' || \Option::get($option, null) !== null) {
                continue;
            }
            if ($base64) {
                $value = (string) base64_decode($value, true);
            }
            if (in_array($option, ['branding.css', 'branding.email_css'])) {
                $value = \App\Misc\Branding::sanitizeCss($value);
            } elseif (in_array($option, ['branding.footer', 'branding.email_header', 'branding.email_footer'])) {
                $value = \Helper::stripDangerousTags($value);
            } elseif (in_array($option, ['branding.logo', 'branding.banner', 'branding.favicon'])) {
                $value = basename($value);
            }
            // The title was "FreeScout" unless changed.
            if ($value !== '' && !($option == 'branding.title' && $value == 'FreeScout')) {
                \Option::set($option, $value);
            }
        }
    }
}

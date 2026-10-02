<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The PHP extensions the installer and System » Status check
 * (config/installer.php, required or optional) cover what Tallport's
 * dependencies require.
 */
class RequirementsTest extends TestCase
{
    /**
     * Extensions PHP 8 can't be built without, or that every PHP package has.
     */
    const ALWAYS_PRESENT = ['date', 'pcre', 'hash', 'json', 'spl', 'reflection', 'standard', 'filter', 'session'];

    public function testRequiredExtensionsAreChecked()
    {
        // Required, or optional with what it is for (shown on both pages).
        $checked = array_map('strtolower', array_merge(config('installer.requirements.php'), array_keys(config('installer.optional'))));

        $installed = json_decode(file_get_contents(base_path('vendor/composer/installed.json')), true);
        $missing = [];
        foreach ($installed['packages'] ?? $installed as $package) {
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                $extension = strtolower(substr($requirement, 4));
                if (str_starts_with($requirement, 'ext-') && !in_array($extension, $checked) && !in_array($extension, self::ALWAYS_PRESENT)) {
                    $missing[$extension][] = $package['name'];
                }
            }
        }

        $this->assertSame([], $missing, 'Add these to installer.requirements.php in config/installer.php');
    }
}

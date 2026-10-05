<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accent colours (FruitUI's named accents): a user's own (users.accent; null: the
 * installation's) and the installation's (option branding.accent). The old Header
 * Color (any hex, branding.header_color) becomes the nearest accent.
 */
class AddAccentColors extends Migration
{
    /**
     * Each accent's colour, to find the nearest hue to an old Header Color.
     */
    const COLORS = [
        'blue' => [0, 122, 255], 'purple' => [175, 82, 222], 'pink' => [255, 45, 85], 'red' => [255, 59, 48],
        'orange' => [255, 149, 0], 'yellow' => [255, 204, 0], 'green' => [52, 199, 89], 'graphite' => [142, 142, 147],
    ];

    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'accent')) {
                $table->string('accent', 16)->nullable();
            }
        });

        $hex = strtolower(ltrim(trim((string) \DB::table('options')->where('name', 'branding.header_color')->value('value')), '#'));
        if (preg_match('/^[0-9a-f]{6}$/', $hex) && $hex != '0078d7') {
            // Greys become graphite; other colours the accent with the nearest hue.
            [$hue, $saturation] = self::hueAndSaturation(array_map('hexdec', str_split($hex, 2)));
            $nearest = 'graphite';
            if ($saturation >= 0.2) {
                $nearest = collect(self::COLORS)->except('graphite')->sortBy(function ($color) use ($hue) {
                    $difference = abs(self::hueAndSaturation($color)[0] - $hue);

                    return min($difference, 360 - $difference);
                })->keys()->first();
            }
            \DB::table('options')->updateOrInsert(['name' => 'branding.accent'], ['value' => $nearest]);
        }
        \DB::table('options')->where('name', 'branding.header_color')->delete();
    }

    /**
     * A colour's hue (0–360) and HSL saturation (0–1).
     */
    public static function hueAndSaturation(array $rgb)
    {
        [$r, $g, $b] = array_map(fn ($value) => $value / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        if ($delta == 0) {
            return [0, 0];
        }
        $lightness = ($max + $min) / 2;
        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        if ($max == $r) {
            $hue = 60 * fmod(($g - $b) / $delta + 6, 6);
        } elseif ($max == $g) {
            $hue = 60 * (($b - $r) / $delta + 2);
        } else {
            $hue = 60 * (($r - $g) / $delta + 4);
        }

        return [$hue, $saturation];
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('accent');
        });
    }
}

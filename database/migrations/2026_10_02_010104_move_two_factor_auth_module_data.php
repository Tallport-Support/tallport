<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;
use ParagonIE\ConstantTime\Base32;

/**
 * Two-factor authentication set up with FreeScout's Two-Factor
 * Authentication module moves to Tallport's own (Laravel Fortify): the same
 * secret, so authenticator apps keep working, and the unused recovery codes.
 * The module is switched off; its table (two_factor_authentications) stays.
 */
class MoveTwoFactorAuthModuleData extends Migration
{
    public function up()
    {
        if (Schema::hasTable('two_factor_authentications')) {
            DB::table('two_factor_authentications')
                ->whereNotNull('enabled_at')
                ->orderBy('id')
                ->each(function ($row) {
                    self::moveUser($row);
                });
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('alias', 'twofactorauth')->update(['active' => false]);
        }
    }

    public function down()
    {
        // The module's table is untouched: turning the module on again uses it.
    }

    /**
     * One user's two-factor authentication, unless they have Tallport's already.
     */
    public static function moveUser($row)
    {
        $user = DB::table('users')->where('id', $row->user_id)->first(['id', 'two_factor_secret']);
        if (!$user || $user->two_factor_secret) {
            return;
        }

        // The secret is stored as raw bytes (base64 encoded on PostgreSQL).
        $secret = is_resource($row->shared_secret) ? stream_get_contents($row->shared_secret) : (string) $row->shared_secret;
        if (DB::connection()->getDriverName() == 'pgsql') {
            $secret = (string) base64_decode($secret);
        }
        if ($secret === '') {
            return;
        }

        // Recovery codes: [{"code": "...", "used_at": null|date}, ...].
        $codes = [];
        foreach ((array) json_decode((string) $row->recovery_codes, true) as $code) {
            if (is_array($code) && !empty($code['code']) && empty($code['used_at'])) {
                $codes[] = (string) $code['code'];
            }
        }

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret'         => Fortify::currentEncrypter()->encrypt(Base32::encodeUpperUnpadded($secret)),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes)),
            'two_factor_confirmed_at'   => $row->enabled_at,
        ]);
    }
}

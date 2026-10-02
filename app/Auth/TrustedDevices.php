<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devices on which a user asked not to be asked for a two-factor code
 * ("Remember this device") for DAYS days, at most MAX per user. The
 * browser keeps a random token in a cookie; the table only its hash.
 */
class TrustedDevices
{
    const DAYS = 14;

    const MAX = 3;

    const TABLE = 'two_factor_trusted_devices';

    /**
     * One cookie per user, so people sharing a computer each have theirs.
     */
    public static function cookieName($user)
    {
        return 'two_factor_device_'.$user->id;
    }

    public static function isTrusted($user, $request)
    {
        $token = $request->cookie(self::cookieName($user));
        if (!$token || !is_string($token)) {
            return false;
        }

        $device = DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->where('token', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        if (!$device) {
            return false;
        }
        DB::table(self::TABLE)->where('id', $device->id)->update(['last_used_at' => now()]);

        return true;
    }

    public static function remember($user, $request)
    {
        $token = Str::random(64);
        DB::table(self::TABLE)->insert([
            'user_id'      => $user->id,
            'token'        => hash('sha256', $token),
            'ip'           => substr((string) $request->ip(), 0, 45),
            'user_agent'   => substr((string) $request->userAgent(), 0, 255),
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::DAYS),
            'created_at'   => now(),
        ]);

        // The newest MAX devices, and not expired.
        DB::table(self::TABLE)->where('user_id', $user->id)->where('expires_at', '<=', now())->delete();
        $keep = DB::table(self::TABLE)->where('user_id', $user->id)->orderBy('id', 'desc')->limit(self::MAX)->pluck('id');
        DB::table(self::TABLE)->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        Cookie::queue(self::cookieName($user), $token, self::DAYS * 1440);
    }

    /**
     * Forget all of a user's devices (two-factor turned off or reset).
     */
    public static function forget($user)
    {
        DB::table(self::TABLE)->where('user_id', $user->id)->delete();
    }

    public static function count($user)
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->where('expires_at', '>', now())->count();
    }
}

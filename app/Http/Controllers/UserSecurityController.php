<?php

namespace App\Http\Controllers;

use App\Auth\TrustedDevices;
use App\Http\Middleware\RequireTwoFactor;
use App\User;
use Illuminate\Http\Request;

/**
 * A user's security page: two-factor authentication and passkeys. Users
 * manage their own (with Laravel Fortify's endpoints, routes/web.php);
 * admins can reset a user's, for someone who lost their phone.
 */
class UserSecurityController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function show($id, Request $request)
    {
        $user = User::findOrFail($id);
        $auth_user = $request->user();
        if ($auth_user->id != $user->id && !$auth_user->isAdmin()) {
            abort(403);
        }
        $own = $auth_user->id == $user->id;

        return view('users/security', [
            'user'           => $user,
            'users'          => $auth_user->isAdmin() ? User::all()->except($id) : collect(),
            'own'            => $own,
            'required'       => (bool) config('app.two_factor_required'),
            'must_turn_on'   => $own && RequireTwoFactor::mustTurnOn($user),
            'recovery_codes' => $own && $user->hasEnabledTwoFactorAuthentication()
                && ($request->boolean('codes') || in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated']))
                ? $user->recoveryCodes() : [],
            'trusted_devices' => TrustedDevices::count($user),
            'passkeys'        => $user->passkeys()->orderBy('id')->get(),
        ]);
    }

    /**
     * An admin turns off a user's two-factor authentication and removes
     * their passkeys and remembered devices (lost phone).
     */
    public function reset($id, Request $request)
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }
        $user = User::findOrFail($id);

        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();
        $user->passkeys()->delete();
        TrustedDevices::forget($user);

        \Session::flash('flash_success_floating', __('Two-factor authentication and passkeys have been reset.'));

        return redirect()->route('users.security', ['id' => $user->id]);
    }

    /**
     * Ask for a code again on all devices.
     */
    public function forgetDevices($id, Request $request)
    {
        $user = $request->user();
        if ($user->id != $id) {
            abort(403);
        }
        TrustedDevices::forget($user);

        \Session::flash('flash_success_floating', __('Remembered devices have been forgotten.'));

        return redirect()->route('users.security', ['id' => $user->id]);
    }
}

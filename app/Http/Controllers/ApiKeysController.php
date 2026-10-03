<?php

namespace App\Http\Controllers;

use App\Api\ApiKey;
use App\User;
use Illuminate\Http\Request;

/**
 * A user's own API keys (Profile » API Keys).
 */
class ApiKeysController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index($id)
    {
        $user = $this->ownUser($id);

        return view('users/api_keys', [
            'user'      => $user,
            'keys'      => ApiKey::where('user_id', $user->id)->orderBy('id', 'desc')->get(),
            'mailboxes' => $user->mailboxesCanView(),
            'new_key'   => session('api_key_created'),
        ]);
    }

    public function action(Request $request, $id)
    {
        $user = $this->ownUser($id);

        switch ($request->action) {
            case 'create':
                $request->validate([
                    'name'    => 'required|string|max:255',
                    'ability' => 'required|in:'.ApiKey::ABILITY_READ.','.ApiKey::ABILITY_WRITE,
                ]);
                [, $token] = ApiKey::generate($user, $request->name, $request->ability, (array) $request->mailboxes);
                // Shown once.
                $request->session()->flash('api_key_created', $token);
                break;

            case 'revoke':
                ApiKey::where('user_id', $user->id)->where('id', $request->key_id)->delete();
                \Session::flash('flash_success_floating', __('API key revoked'));
                break;
        }

        return redirect()->route('users.api_keys', ['id' => $user->id]);
    }

    /**
     * Keys are made by their user only.
     */
    protected function ownUser($id)
    {
        $user = User::findOrFail($id);
        if ($user->id != auth()->user()->id) {
            abort(403);
        }

        return $user;
    }
}

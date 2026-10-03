<?php

namespace App\Http\Controllers;

use App\Api\ApiKey;
use App\Api\Webhook;
use Illuminate\Http\Request;

/**
 * Settings » API & Webhooks: a new global API key, revoking users' keys,
 * webhooks.
 */
class ApiSettingsController extends Controller
{
    public function action(Request $request)
    {
        switch ($request->action) {
            case 'regenerate_key':
                \Helper::setEnvFileVar('APIWEBHOOKS_API_KEY_SALT', \Str::random(10));
                \Helper::clearCache(['--doNotGenerateVars' => true]);
                \Session::flash('flash_success_floating', __('A new API key has been generated'));
                break;

            case 'revoke_key':
                ApiKey::where('id', $request->key_id)->delete();
                \Session::flash('flash_success_floating', __('API key revoked'));
                break;

            case 'save_webhook':
                $request->validate([
                    'url'      => 'required|url:http,https|max:255',
                    'events'   => 'required|array',
                    'events.*' => 'in:'.implode(',', Webhook::allEvents()),
                ]);
                $webhook = $request->webhook_id ? Webhook::findOrFail($request->webhook_id) : new Webhook();
                $webhook->url = $request->url;
                $webhook->events = array_values($request->events);
                $webhook->mailboxes = array_values(array_map('intval', (array) $request->mailboxes)) ?: null;
                $webhook->save();
                \Session::flash('flash_success_floating', __('Webhook saved'));
                break;

            case 'delete_webhook':
                $webhook = Webhook::findOrFail($request->webhook_id);
                $webhook->logs()->delete();
                $webhook->delete();
                \Session::flash('flash_success_floating', __('Webhook deleted'));
                break;
        }

        return redirect()->route('settings', ['section' => 'api']);
    }
}

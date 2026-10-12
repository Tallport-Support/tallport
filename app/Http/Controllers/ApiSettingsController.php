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
    /**
     * A webhook as a level beside API & Webhooks; "new": one to add.
     */
    public function webhook($id)
    {
        $webhook = $id == 'new' ? new Webhook() : Webhook::findOrFail($id);

        return view('settings/view', (new SettingsController())->sectionData('api') + ['webhook' => $webhook]);
    }

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
                $validator = \Validator::make($request->all(), [
                    'url'      => 'required|url:http,https|max:255',
                    'events'   => 'required|array',
                    'events.*' => 'in:'.implode(',', Webhook::allEvents()),
                ]);
                if ($validator->fails()) {
                    // Saved as it changes (Tallport.autoSave()): said on the field's row.
                    if ($request->ajax()) {
                        return response()->json(['status' => 'error', 'msg' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
                    }

                    return back()->withErrors($validator)->withInput();
                }
                $webhook = $request->webhook_id ? Webhook::findOrFail($request->webhook_id) : new Webhook();
                $webhook->url = $request->url;
                $webhook->events = array_values($request->events);
                $webhook->mailboxes = array_values(array_map('intval', (array) $request->mailboxes)) ?: null;
                $webhook->save();
                // A webhook's level saves as it changes; a new one opens its level.
                if ($request->ajax()) {
                    return response()->json(['status' => 'success']);
                }
                \Session::flash('flash_success_floating', __('Webhook saved'));

                return redirect()->route('settings.api.webhook', ['id' => $webhook->id]);

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

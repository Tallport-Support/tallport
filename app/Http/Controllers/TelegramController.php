<?php

namespace App\Http\Controllers;

use App\Mailbox;
use App\Telegram\Incoming;
use App\Telegram\Telegram;
use App\Telegram\TelegramException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A mailbox's Telegram bot: its settings, and the webhook Telegram sends
 * the bot's updates to.
 */
class TelegramController extends Controller
{
    /**
     * Days update IDs are kept to recognise updates Telegram sends again.
     */
    const KEEP_UPDATES_DAYS = 7;

    public function webhook(Request $request, $mailbox_id)
    {
        $mailbox = Mailbox::find($mailbox_id);
        if (!$mailbox || !Telegram::isEnabled($mailbox)) {
            // Nothing to do: Telegram need not send it again.
            return response()->json(['ok' => true]);
        }
        if (!hash_equals(Telegram::webhookSecret($mailbox), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            Telegram::log('Webhook request without the right secret from '.$request->ip().' ignored.', $mailbox);

            return response()->json(['ok' => false], 403);
        }
        $update = $request->json()->all();
        if (empty($update['update_id'])) {
            return response()->json(['ok' => true]);
        }

        DB::table('telegram_updates')->where('created_at', '<', now()->subDays(self::KEEP_UPDATES_DAYS))->delete();
        $new = DB::table('telegram_updates')->insertOrIgnore([
            'mailbox_id' => $mailbox->id,
            'update_id'  => (int) $update['update_id'],
            'created_at' => now(),
        ]);
        if (!$new) {
            // Processed already.
            return response()->json(['ok' => true]);
        }

        try {
            Incoming::handle($mailbox, $update);
        } catch (\Throwable $e) {
            // Telegram sends it again later.
            DB::table('telegram_updates')->where('mailbox_id', $mailbox->id)->where('update_id', (int) $update['update_id'])->delete();
            \Helper::logException($e, '[Telegram] Update '.$update['update_id'].' for mailbox '.$mailbox->id.':');
            \App\Misc\ChatLog::failure('telegram', $mailbox->id, 'receive', $e);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    public function settings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $settings = Telegram::settings($mailbox);

        $bot = null;
        $webhook = null;
        $error = '';
        if ($settings['token'] !== '') {
            try {
                $client = Telegram::client($mailbox);
                $bot = $client->getMe();
                $webhook = $client->getWebhookInfo();
            } catch (TelegramException $e) {
                \App\Misc\ChatLog::failure('telegram', $mailbox->id, 'connection', $e);
                $error = $e->getMessage();
            }
        }

        return view('mailboxes/telegram', [
            'mailbox'         => $mailbox,
            'settings'        => $settings,
            'bot'             => $bot,
            'webhook'         => $webhook,
            'webhook_ok'      => $webhook && ($webhook['url'] ?? '') == Telegram::webhookUrl($mailbox),
            'telegram_error'  => $error,
        ]);
    }

    public function settingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $before = Telegram::settings($mailbox);

        $token = trim((string) $request->input('token'));
        if (\Helper::isSafePassword($token)) {
            $token = $before['token'];
        }
        $settings = [
            'enabled'      => (bool) $request->input('enabled'),
            'token'        => $token,
            'ignore_start' => (bool) $request->input('ignore_start'),
        ];
        if ($settings['enabled'] && $token === '') {
            if ($request->ajax()) {
                return response()->json(['status' => 'error', 'msg' => __('Enter the bot token from @BotFather.'), 'errors' => ['token' => [__('Enter the bot token from @BotFather.')]]], 422);
            }

            return back()->withInput()->withErrors(['token' => __('Enter the bot token from @BotFather.')]);
        }
        Telegram::saveSettings($mailbox, $settings);

        // Saved as it changes (in the background): Telegram is asked only when the bot or its being on
        // changed; a form sent the usual way connects it again.
        $reconnect = !$request->ajax() || $settings['enabled'] != $before['enabled'] || $settings['token'] !== $before['token'];
        try {
            if (!$reconnect) {
                // Nothing to tell Telegram.
            } elseif ($settings['enabled']) {
                Telegram::client($mailbox)->getMe();
                Telegram::registerWebhook($mailbox);
                // Its description: the greeting.
                Telegram::syncDescriptionLater($mailbox);
            } elseif ($before['enabled'] && $before['token'] !== '') {
                (new \App\Telegram\Client($before['token']))->deleteWebhook();
            }
        } catch (TelegramException $e) {
            \App\Misc\ChatLog::failure('telegram', $mailbox->id, 'connection', $e);
            if ($request->ajax()) {
                return response()->json(['status' => 'error', 'msg' => __('Telegram').': '.$e->getMessage()]);
            }
            \Session::flash('flash_error_floating', __('Telegram').': '.$e->getMessage());

            return redirect()->route('mailboxes.telegram', ['id' => $mailbox->id]);
        }

        if (!$request->ajax()) {
            \Session::flash('flash_success_floating', __('Settings updated'));
        }

        return $this->savedResponse(redirect()->route('mailboxes.telegram', ['id' => $mailbox->id]));
    }
}

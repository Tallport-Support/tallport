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
            'languages'       => \App\Ai\Settings::displayNames(),
            'active_language' => session('telegram_language'),
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
        $languages = \App\Ai\Settings::LANGUAGES;
        $auto_replies = [];
        foreach ((array) $request->input('auto_replies', []) as $language => $text) {
            if (isset($languages[$language]) && $language != $request->remove_language) {
                $auto_replies[$language] = trim((string) $text);
            }
        }
        if ($request->filled('add_language') && isset($languages[$request->add_language_code])) {
            $auto_replies += [$request->add_language_code => ''];
            \Session::flash('telegram_language', $request->add_language_code);
        }
        $settings = [
            'enabled'      => (bool) $request->input('enabled'),
            'token'        => $token,
            'auto_reply'   => trim((string) $request->input('auto_reply')),
            'auto_replies' => $auto_replies,
            'ignore_start' => (bool) $request->input('ignore_start'),
        ];
        if ($settings['enabled'] && $token === '') {
            return back()->withInput()->withErrors(['token' => __('Enter the bot token from @BotFather.')]);
        }
        Telegram::saveSettings($mailbox, $settings);

        try {
            if ($settings['enabled']) {
                Telegram::client($mailbox)->getMe();
                Telegram::registerWebhook($mailbox);
            } elseif ($before['enabled'] && $before['token'] !== '') {
                (new \App\Telegram\Client($before['token']))->deleteWebhook();
            }
        } catch (TelegramException $e) {
            \App\Misc\ChatLog::failure('telegram', $mailbox->id, 'connection', $e);
            \Session::flash('flash_error_floating', __('Telegram').': '.$e->getMessage());

            return redirect()->route('mailboxes.telegram', ['id' => $mailbox->id]);
        }

        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('mailboxes.telegram', ['id' => $mailbox->id]);
    }
}

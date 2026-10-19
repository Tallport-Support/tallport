<?php

namespace App\Http\Controllers;

use App\Mailbox;
use App\WebChat\WebChat;
use Illuminate\Http\Request;

/**
 * A mailbox's Website Chat (App\WebChat): on or off, the websites allowed, and what visitors see.
 * Saved with its button: the greeting goes to visitors, never half-written.
 */
class WebChatSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function settings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $settings = WebChat::settings($mailbox);
        $script = url('/t/t.js');

        return view('mailboxes/web_chat', [
            'mailbox'  => $mailbox,
            'settings' => $settings,
            'snippet'  => $settings['widget'] === '' ? '' : implode("\n", [
                '<script>',
                '  window.tallport = window.tallport || function () { (tallport.q = tallport.q || []).push(arguments); };',
                "  tallport('init', { widget: '".$settings['widget']."' });",
                '</script>',
                '<script src="'.$script.'" async></script>',
            ]),
            'link'     => $settings['widget'] === '' ? '' : url('/t/w').'?widget='.$settings['widget'],
        ]);
    }

    public function settingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $request->validate([
            'origins'    => 'nullable|string|max:5000',
            'greeting'   => 'nullable|string|max:1000',
            'reply_time' => 'nullable|string|max:100',
            'accent'     => 'nullable|in:'.implode(',', \FruitUI\Fruit::ACCENTS),
        ]);

        $origins = [];
        foreach (preg_split('/[\s,]+/', (string) $request->origins, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $origin = WebChat::origin($line);
            if (!$origin) {
                return back()->withInput()->withErrors(['origins' => __('Not a website address: :address', ['address' => $line])]);
            }
            $origins[] = $origin;
        }
        $settings = WebChat::settings($mailbox);
        WebChat::saveSettings($mailbox, [
            'enabled'    => (bool) $request->enabled,
            'widget'     => $settings['widget'] !== '' ? $settings['widget'] : WebChat::newWidgetId(),
            'origins'    => array_values(array_unique($origins)),
            'greeting'   => trim((string) $request->greeting),
            'reply_time' => trim((string) $request->reply_time),
            'accent'     => $request->accent ?: 'blue',
        ]);
        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('mailboxes.web_chat', ['id' => $mailbox->id]);
    }
}

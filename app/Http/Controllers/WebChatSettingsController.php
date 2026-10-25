<?php

namespace App\Http\Controllers;

use App\Mailbox;
use App\WebChat\WebChat;
use Illuminate\Http\Request;

/**
 * A mailbox's Website Chat (App\WebChat): on or off, the websites allowed, and what visitors see
 * (its greeting and auto reply: App\AutoReply\ChannelMessages). Saved with its button.
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
            'secret'   => \App\WebChat\Identity::hasSecret($mailbox) ? \App\WebChat\Identity::secret($mailbox) : '',
            'limits'          => \App\WebChat\Spam::limits($mailbox),
            'proxy_secret' => WebChat::hasSecret($mailbox, 'proxy_secret') ? WebChat::secret($mailbox, 'proxy_secret') : '',
        ]);
    }

    public function settingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        // The signing secret for signed-in visitors: made, or made anew (tokens signed with the old one stop working).
        if (in_array($request->action, ['make_secret', 'rotate_secret', 'make_proxy_secret', 'rotate_proxy_secret'])) {
            WebChat::secret($mailbox, str_contains($request->action, 'proxy') ? 'proxy_secret' : 'identity_secret', true);
            \Session::flash('flash_success_floating', str_starts_with($request->action, 'rotate') ? __('Secret rotated') : __('Secret made'));

            return redirect()->route('mailboxes.web_chat', ['id' => $mailbox->id]);
        }
        $request->validate([
            'origins'    => 'nullable|string|max:5000',
            'limit_new_chats'  => 'nullable|integer|min:1|max:100000',
            'limit_messages'   => 'nullable|integer|min:1|max:1000000',
            'limit_uploads_mb' => 'nullable|integer|min:1|max:1000000',
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
            'limit_new_chats'  => (int) ($request->limit_new_chats ?: \App\WebChat\Spam::LIMITS['new_chats']),
            'limit_messages'   => (int) ($request->limit_messages ?: \App\WebChat\Spam::LIMITS['messages']),
            'limit_uploads_mb' => (int) ($request->limit_uploads_mb ?: \App\WebChat\Spam::LIMITS['uploads_mb']),
            'enabled'    => (bool) $request->enabled,
            'widget'     => $settings['widget'] !== '' ? $settings['widget'] : WebChat::newWidgetId(),
            'origins'    => array_values(array_unique($origins)),
            'reply_time' => trim((string) $request->reply_time),
            'accent'     => $request->accent ?: 'blue',
        ]);
        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('mailboxes.web_chat', ['id' => $mailbox->id]);
    }
}

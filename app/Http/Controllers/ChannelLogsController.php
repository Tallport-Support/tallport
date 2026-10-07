<?php

namespace App\Http\Controllers;

use App\Mailbox;
use App\Nostr\NostrEvent;
use App\Telegram\TelegramSend;
use Illuminate\Http\Request;

/**
 * Manage » Logs » Outgoing Telegram and Outgoing Nostr: each try at sending a reply,
 * newest first, filtered by outcome (failed) and mailbox.
 */
class ChannelLogsController extends Controller
{
    const PER_PAGE = 50;

    /**
     * Each try of SendReplyToTelegram (App\Telegram\TelegramSend).
     */
    public function telegram(Request $request)
    {
        $query = TelegramSend::with(['mailbox', 'conversation', 'customer'])->orderBy('id', 'desc');
        $mailbox_ids = TelegramSend::select('mailbox_id')->distinct()->pluck('mailbox_id');
        [$outcome, $mailbox_id] = $this->filter($request, $query, [TelegramSend::STATUS_FAILED, TelegramSend::STATUS_RETRYING]);

        return view('secure/telegram_log', [
            'sends'      => $query->paginate(self::PER_PAGE)->withQueryString(),
            'mailboxes'  => Mailbox::whereIn('id', $mailbox_ids)->orderBy('name')->get(),
            'outcome'    => $outcome,
            'mailbox_id' => $mailbox_id,
        ]);
    }

    /**
     * Each message sent over Nostr (outgoing rows of nostr_events): replies and auto replies,
     * with what each relay said.
     */
    public function nostr(Request $request)
    {
        $query = NostrEvent::with(['mailbox', 'conversation.customer'])->where('direction', NostrEvent::DIRECTION_OUT)->orderBy('id', 'desc');
        $mailbox_ids = NostrEvent::where('direction', NostrEvent::DIRECTION_OUT)->select('mailbox_id')->distinct()->pluck('mailbox_id');
        [$outcome, $mailbox_id] = $this->filter($request, $query, [NostrEvent::STATUS_FAILED]);

        return view('secure/nostr_log', [
            'events'     => $query->paginate(self::PER_PAGE)->withQueryString(),
            'mailboxes'  => Mailbox::whereIn('id', $mailbox_ids)->orderBy('name')->get(),
            'outcome'    => $outcome,
            'mailbox_id' => $mailbox_id,
        ]);
    }

    /**
     * The filters both logs have: [outcome, mailbox ID].
     */
    protected function filter(Request $request, $query, array $failed_statuses)
    {
        $outcome = (string) $request->input('outcome');
        if ($outcome == 'failed') {
            $query->whereIn('status', $failed_statuses);
        }
        $mailbox_id = (int) $request->input('mailbox_id');
        if ($mailbox_id) {
            $query->where('mailbox_id', $mailbox_id);
        }

        return [$outcome, $mailbox_id];
    }
}

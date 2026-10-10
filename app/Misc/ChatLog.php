<?php

namespace App\Misc;

use App\ActivityLog;
use App\Conversation;
use App\Mailbox;
use App\Matrix\MatrixEvent;
use App\Nostr\NostrEvent;
use App\Telegram\TelegramSend;
use Illuminate\Support\Facades\DB;

/**
 * Channel diagnostics share the activity log's storage and retention. Delivery
 * records stay with their protocols; the log reader includes their existing history.
 */
class ChatLog
{
    public static function record($channel, $mailbox_id, $type, $status, $message, array $details = [])
    {
        if (!config('activitylog.enabled')) {
            return;
        }
        if (isset($details['relay'])) {
            $details['relay'] = parse_url($details['relay'], PHP_URL_HOST) ?: '';
        }
        try {
            DB::transaction(function () use ($channel, $mailbox_id, $type, $status, $message, $details) {
                $entry = new ActivityLog();
                $entry->log_name = $channel;
                $entry->subject_type = Mailbox::class;
                $entry->subject_id = $mailbox_id;
                $entry->description = mb_substr($message, 0, 1000);
                $entry->properties = ['type' => $type, 'status' => $status, 'details' => array_intersect_key($details, array_flip(['exception', 'code', 'event_id', 'thread_id', 'relay']))];
                $entry->save();
            });
        } catch (\Throwable $e) {
            \Log::error('Chat log could not be saved.', ['channel' => $channel, 'mailbox_id' => $mailbox_id]);
        }
    }

    public static function failure($channel, $mailbox_id, $type, \Throwable $error, array $details = [])
    {
        $message = $error instanceof \App\Matrix\MatrixException || $error instanceof \App\Telegram\TelegramException
            ? $error->getMessage() : 'Operation failed.';
        self::record($channel, $mailbox_id, $type, 'failed', $message, ['exception' => get_class($error), 'code' => $error->getCode()] + $details);
    }

    public static function page($channel, $outcome, $mailbox_id)
    {
        $activity = ActivityLog::query()->where('log_name', $channel)
            ->select(['id', 'created_at', 'subject_id as mailbox_id', 'properties->status as outcome'])->selectRaw("'activity' as source");
        if ($channel === 'telegram') {
            $delivery = TelegramSend::query()->select(['id', 'created_at', 'mailbox_id'])
                ->selectRaw("CASE WHEN status = 1 THEN 'succeeded' ELSE 'failed' END as outcome, 'delivery' as source");
            $model = TelegramSend::class;
        } elseif ($channel === 'nostr') {
            $delivery = NostrEvent::query()->select(['id', 'created_at', 'mailbox_id'])
                ->selectRaw("CASE WHEN status = 1 THEN 'succeeded' WHEN status = 2 THEN 'failed' ELSE 'pending' END as outcome, 'delivery' as source");
            $model = NostrEvent::class;
        } else {
            $delivery = MatrixEvent::query()->join('matrix_mailboxes', 'matrix_mailboxes.id', '=', 'matrix_events.matrix_mailbox_id')
                ->whereIn('kind', ['incoming', 'outgoing'])->select(['matrix_events.id', 'matrix_events.created_at', 'matrix_mailboxes.mailbox_id'])
                ->selectRaw("CASE WHEN matrix_events.status IN ('sent', 'received') THEN 'succeeded' WHEN matrix_events.status = 'rejected' OR (matrix_events.status = 'pending' AND attempts > 0) THEN 'failed' ELSE matrix_events.status END as outcome, 'delivery' as source");
            $model = MatrixEvent::class;
        }
        $query = DB::query()->fromSub($activity->unionAll($delivery)->toBase(), 'channel_log');
        $mailbox_ids = (clone $query)->distinct()->pluck('mailbox_id');
        if ($outcome === 'failed') {
            $query->where('outcome', 'failed');
        }
        if ($mailbox_id) {
            $query->where('mailbox_id', $mailbox_id);
        }
        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->orderBy('source')->paginate(50)->withQueryString();
        $rows = $entries->getCollection();
        $activities = ActivityLog::whereIn('id', $rows->where('source', 'activity')->pluck('id'))->get()->keyBy('id');
        $deliveries = $model::whereIn('id', $rows->where('source', 'delivery')->pluck('id'));
        if ($channel !== 'matrix') {
            $deliveries->with($channel === 'telegram' ? ['conversation', 'customer'] : ['conversation.customer']);
        }
        // The encrypted Matrix payload is never needed to display a log.
        $deliveries = $deliveries->get($channel === 'matrix' ? ['id', 'kind', 'status', 'attempts', 'thread_id', 'conversation_id', 'remote_id', 'transaction_id', 'room_id'] : ['*'])->keyBy('id');
        $thread_ids = $activities->map(fn ($entry) => $entry->properties['details']['thread_id'] ?? null);
        if ($channel === 'matrix') {
            $thread_ids = $thread_ids->merge($deliveries->pluck('thread_id'));
        }
        $threads = \App\Thread::whereIn('id', $thread_ids->filter())->pluck('conversation_id', 'id');
        $conversations = Conversation::whereIn('id', $deliveries->pluck('conversation_id')->merge($threads->values())->filter())->get()->keyBy('id');
        $mailboxes = Mailbox::whereIn('id', $mailbox_ids)->orderBy('name')->get();
        $names = $mailboxes->keyBy('id');
        $entries->setCollection($rows->map(function ($row) use ($activities, $deliveries, $names, $threads, $conversations) {
            $entry = $row->source === 'activity' ? $activities->get($row->id) : $deliveries->get($row->id);
            return self::present($row, $entry, $names->get($row->mailbox_id), $threads, $conversations);
        }));

        return compact('entries', 'mailboxes', 'outcome', 'mailbox_id', 'channel');
    }

    private static function present($row, $entry, $mailbox, $threads, $conversations)
    {
        $result = ['key' => $row->source.'-'.$row->id, 'date' => $row->created_at, 'mailbox' => $mailbox, 'conversation' => null,
            'customer' => '', 'type' => '', 'message' => '', 'details' => [], 'status' => '', 'tone' => 'neutral'];
        if ($entry instanceof ActivityLog) {
            $result['type'] = $entry->properties['type'] ?? '';
            $result['message'] = $entry->description;
            $result['details'] = $entry->properties['details'] ?? [];
            $result['conversation'] = $conversations->get($threads->get($result['details']['thread_id'] ?? null));
        } elseif ($entry instanceof TelegramSend) {
            [$result['status'], $result['tone']] = $entry->statusName();
            $result['type'] = 'send';
            $result['conversation'] = $entry->conversation;
            $result['customer'] = $entry->customer ? $entry->customer->getFullName(true).(TelegramSend::username($entry->customer) !== '' ? ' @'.TelegramSend::username($entry->customer) : '') : '';
            $result['message'] = $entry->error;
            $result['details'] = [__('Attempt') => $entry->attempt, __('Message IDs') => implode(', ', $entry->message_ids ?? []), __('Files') => $entry->files];
        } elseif ($entry instanceof NostrEvent) {
            $result['type'] = $entry->direction == NostrEvent::DIRECTION_OUT ? 'send' : 'receive';
            $result['conversation'] = $entry->conversation;
            $result['customer'] = ($entry->conversation && $entry->conversation->customer ? $entry->conversation->customer->getFullName(true).' ' : '').($entry->pubkey ? \App\Nostr\Keys::shortNpub($entry->pubkey) : '');
            $result['message'] = $entry->error;
            if ($entry->direction == NostrEvent::DIRECTION_OUT && !$entry->thread_id) {
                $result['details'][__('Type')] = __('Auto Reply');
            }
            if ($entry->pubkey) {
                $result['details'][__('Key')] = \App\Nostr\Keys::npub($entry->pubkey);
            }
            $relays = $entry->getRelays();
            if ($entry->relay) {
                $result['details'][__('Relays')] = $entry->relay;
            }
            if ($relays) {
                $result['details'][__('Relays')] = __(':accepted of :total', ['accepted' => count(array_filter($relays, fn ($relay) => !empty($relay['ok']))), 'total' => count($relays)]);
            }
            foreach ($relays as $url => $relay) {
                $result['details'][$url] = !empty($relay['ok']) ? __('Accepted') : __('Failed').': '.($relay['message'] ?? '');
            }
        } elseif ($entry instanceof MatrixEvent) {
            $result['type'] = $entry->kind === 'incoming' ? 'receive' : 'send';
            $result['conversation'] = $conversations->get($entry->conversation_id ?: $threads->get($entry->thread_id));
            $result['details'] = [__('Status') => $entry->status, __('Attempt') => $entry->attempts, __('Message IDs') => $entry->remote_id, 'transaction_id' => $entry->transaction_id, 'room_id' => $entry->room_id];
        }
        if (!$result['status']) {
            $result['status'] = $row->outcome === 'succeeded' ? __('Succeeded') : ($row->outcome === 'failed' ? __('Failed') : ($row->outcome === 'pending' ? __('Pending') : ($row->outcome === 'info' ? __('Info') : ($row->outcome ?? '—'))));
            $result['tone'] = $row->outcome === 'succeeded' ? 'success' : ($row->outcome === 'failed' ? 'danger' : 'neutral');
        }
        $result['type'] = ['send' => __('Send'), 'receive' => __('Receive'), 'connection' => __('Connection')][$result['type']] ?? $result['type'];

        return $result;
    }
}

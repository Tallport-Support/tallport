<?php

namespace App\Http\Controllers;

use App\Ai\CustomerContext;
use App\Ai\DraftJob;
use App\Ai\Drafts;
use App\Ai\Settings;
use App\Ai\StreamThrottle;
use App\Conversation;
use App\Mailbox;
use Illuminate\Http\Request;

/**
 * AI Assistant reply drafts: asked for in a conversation, and written into the browser as the
 * AI writes them (server-sent events).
 */
class AiDraftsController extends Controller
{
    /**
     * The draft so far is sent at most this often (seconds).
     */
    const STREAM_INTERVAL = 0.1;

    public function store($id)
    {
        $conversation = Conversation::findOrFail($id);
        $user = auth()->user();
        if (!Drafts::allowed($user, $conversation)) {
            abort(403);
        }
        if (Drafts::limitReached($user)) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('You have made the most drafts allowed for today.'),
            ], 429);
        }
        if (!Settings::withinBudget($conversation->mailbox)) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('This mailbox has used its AI tokens for today.'),
            ], 429);
        }

        // Kept for the user's drafts per day, with how it went.
        $draft_job = new DraftJob();
        $draft_job->conversation_id = $conversation->id;
        $draft_job->user_id = $user->id;
        $draft_job->status = DraftJob::STATUS_RUNNING;
        $draft_job->started_at = now();
        $draft_job->save();
        $language = Settings::language($conversation->mailbox, $user);

        // Events: {"draft": the draft so far}, then the draft and its details ("status": "success"), or the error.
        return response()->stream(function () use ($draft_job, $conversation, $language) {
            // Finished (and kept) also when the user leaves.
            ignore_user_abort(true);
            @set_time_limit(240);
            $send = function (array $data) {
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $throttle = new StreamThrottle(self::STREAM_INTERVAL);
            try {
                $draft_job->result = Drafts::draft($conversation, $language, function ($draft) use ($send, $throttle) {
                    if (trim($draft) !== '' && $throttle->ready()) {
                        $send(['draft' => $draft]);
                    }
                });
                $draft_job->status = DraftJob::STATUS_COMPLETED;
            } catch (\Throwable $e) {
                $draft_job->status = DraftJob::STATUS_FAILED;
                $draft_job->error_type = get_class($e);
                $draft_job->error_message = __('Could not draft a reply.');
                $draft_job->error_detail = mb_substr($e->getMessage(), 0, 2000);
                \Helper::logException($e, '[AI] Draft for conversation #'.$draft_job->conversation_id.':');
            }
            $draft_job->completed_at = now();
            $draft_job->save();

            $send($draft_job->status == DraftJob::STATUS_COMPLETED
                ? ['status' => 'success', 'draft_status' => $draft_job->status] + (array) $draft_job->result
                : ['status' => 'error', 'draft_status' => $draft_job->status, 'msg' => $draft_job->error_message, 'detail' => $draft_job->error_detail]);
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A test's answer shown up to this much (Settings › Mailboxes › AI Assistant).
     */
    const TEST_BODY_BYTES = 4096;

    /**
     * Send a test request to a mailbox's customer context URL (admins).
     */
    public function testCustomerContext(Request $request)
    {
        $mailbox = Mailbox::findOrFail((int) $request->mailbox_id);
        $validator = \Validator::make($request->all(), [
            'email' => 'required|email|max:191',
            'url'   => 'required|url:http,https|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'msg' => $validator->errors()->first()]);
        }
        $settings = CustomerContext::settings($mailbox);
        $secret = (string) $request->secret_key;

        $started = microtime(true);
        try {
            $result = CustomerContext::test($mailbox, $request->email, [
                'url'              => $request->url,
                'secret_key'       => preg_match('/^\*+$/', $secret) ? $settings['secret_key'] : $secret,
                'signature_header' => $request->signature_header,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        // How long and how much, and the start of a long answer.
        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
        $result['bytes'] = strlen($result['body']);
        if ($result['bytes'] > self::TEST_BODY_BYTES) {
            $result['body'] = mb_strcut($result['body'], 0, self::TEST_BODY_BYTES).'…';
        }
        unset($result['payload']);

        return response()->json(['status' => 'success'] + $result);
    }
}

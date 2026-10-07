<?php

namespace App\Http\Controllers;

use App\Ai\CustomerContext;
use App\Ai\DraftJob;
use App\Ai\Drafts;
use App\Ai\Settings;
use App\Conversation;
use App\Jobs\AiDraftReply;
use App\Mailbox;
use Illuminate\Http\Request;

/**
 * AI Assistant reply drafts: asked for in a conversation, made by a queued
 * job and fetched by the browser until done.
 */
class AiDraftsController extends Controller
{
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

        $draft_job = new DraftJob();
        $draft_job->conversation_id = $conversation->id;
        $draft_job->user_id = $user->id;
        $draft_job->status = DraftJob::STATUS_PENDING;
        $draft_job->save();

        AiDraftReply::dispatch($draft_job->id, Settings::language($conversation->mailbox, $user));

        return response()->json([
            'status'       => 'success',
            'draft_status' => $draft_job->fresh()->status,
            'poll_url'     => route('ai.drafts.show', ['id' => $draft_job->id]),
        ]);
    }

    public function show($id)
    {
        $draft_job = DraftJob::findOrFail($id);
        if ($draft_job->user_id != auth()->id()) {
            abort(403);
        }

        if ($draft_job->status == DraftJob::STATUS_COMPLETED) {
            return response()->json(['status' => 'success', 'draft_status' => $draft_job->status] + (array) $draft_job->result);
        }
        if ($draft_job->status == DraftJob::STATUS_FAILED) {
            return response()->json([
                'status'       => 'error',
                'draft_status' => $draft_job->status,
                'msg'          => $draft_job->error_message ?: __('Could not draft a reply.'),
                'detail'       => $draft_job->error_detail,
            ]);
        }

        return response()->json(['status' => 'success', 'draft_status' => $draft_job->status]);
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

<?php

namespace App\Ai;

use App\Ai\Agents\ResolvedCheck;
use App\Ai\Agents\TallportAgent;
use App\Conversation;
use App\Jobs\AiCheckResolved;
use App\Thread;

/**
 * Close When Resolved (a mailbox's AI page, on by default): a customer's message saying they need
 * nothing more (Agents\ResolvedCheck) closes the conversation, as the AI's, on any channel. In a
 * conversation the AI agent is still asking in, the agent decides that itself (ChatAgent).
 */
class Resolved
{
    /**
     * Messages the AI reads: the customer's latest and those before it.
     */
    const MESSAGES = 6;

    const MAX_MESSAGE_CHARS = 1000;

    /**
     * A longer message is about more than that: not checked.
     */
    const MAX_LENGTH = 1500;

    /**
     * The customer replied: checked once they've finished writing (chats come in pieces).
     */
    public static function request(Conversation $conversation, Thread $thread)
    {
        if (self::wanted($conversation, $thread)) {
            AiCheckResolved::dispatch($conversation->id, $thread->id)->delay(now()->addSeconds(ChatAgent::WAIT));
        }
    }

    /**
     * Checked only while the message is the conversation's latest (nothing from either side
     * since), also once the AI has answered.
     */
    public static function check(Conversation $conversation, $thread_id)
    {
        $messages = self::messages($conversation);
        $thread = $messages->first();
        if (!$thread || $thread->id != $thread_id || !self::wanted($conversation, $thread) || !Settings::withinBudget($conversation->mailbox)) {
            return;
        }
        $text = self::text($thread);
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return;
        }
        $data = $messages->reverse()->map(fn ($message) => [
            'from' => $message->type == Thread::TYPE_CUSTOMER ? 'customer' : 'staff',
            'body' => $message->id == $thread->id ? $text : mb_substr(self::text($message), 0, self::MAX_MESSAGE_CHARS),
        ])->values()->all();
        try {
            $answer = (new ResolvedCheck())->recordFor(Usage::FEATURE_CLOSING, $conversation)->prompt(TallportAgent::data('conversation', $data))->toArray();
        } catch (\Throwable $e) {
            Errors::report($e, 'Close When Resolved in conversation #'.$conversation->number.':');

            return;
        }
        if (($answer['resolved'] ?? false) !== true) {
            return;
        }
        $conversation->refresh();
        $latest = self::messages($conversation)->first();
        if ($latest && $latest->id == $thread->id && self::wanted($conversation, $latest)) {
            $conversation->changeStatus(Conversation::STATUS_CLOSED, ChatAgent::user());
        }
    }

    /**
     * An open conversation (not imported), a customer's own message (not an auto-responder's or a
     * bounce), the AI set up and this on for the mailbox.
     */
    protected static function wanted(Conversation $conversation, Thread $thread)
    {
        return ($conversation->isActive() || $conversation->isPending())
            && !$conversation->imported && $conversation->state == Conversation::STATE_PUBLISHED
            && $thread->type == Thread::TYPE_CUSTOMER && !$thread->isAutoResponder() && !$thread->isBounce()
            && Settings::isConfigured() && Settings::enabled('closing', $conversation->mailbox)
            && !ChatAgent::handles($conversation);
    }

    /**
     * The latest messages, newest first.
     */
    protected static function messages(Conversation $conversation)
    {
        return $conversation->threads()->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)->orderBy('id', 'desc')->limit(self::MESSAGES)->get();
    }

    protected static function text(Thread $thread)
    {
        return trim(preg_replace("/\n{3,}/", "\n\n", (string) $thread->getBodyAsText()));
    }
}

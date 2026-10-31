<?php

namespace App\Ai;

use App\Ai\Agents\ChatIntake;
use App\AutoReply\ChannelMessages;
use App\Conversation;
use App\Jobs\AiChatAgentReply;
use App\Jobs\AiChatAgentSilence;
use App\Mailbox;
use App\Subscription;
use App\Telegram\Telegram;
use App\Thread;
use App\User;

/**
 * The AI agent: a robot user (like Workflows') who takes new conversations on the channels a mailbox
 * turns it on for, email and chats alike. It asks the customer for what the team needs to know
 * (Agents\ChatIntake), never answering, at most MAX_QUESTIONS times, then hands the conversation to
 * the team: a reply drafted for them, unassigned, notified as a new conversation. While it has one, it's
 * Assigned to it and nobody is notified; a person replying or reassigning it takes it. ("Chat" in
 * names here means any conversation.)
 */
class ChatAgent
{
    const EMAIL = 'tallport-ai@example.org';

    /**
     * The channels it can take (App\AutoReply\ChannelMessages keys).
     */
    const CHANNELS = ['email', 'webchat', 'telegram', 'matrix', 'nostr'];

    const MAX_QUESTIONS = 3;

    /**
     * Seconds it waits for the rest of a customer's message: chats come in pieces.
     */
    const WAIT = 5;

    /**
     * Minutes without an answer to its question: a chat with a request goes to the team, one
     * without (a greeting only) closes.
     */
    const HAND_OVER_AFTER = 10;

    const CLOSE_AFTER = 120;

    /**
     * Seconds the customer sees it writing at most: a run that dies leaves no "typing" behind.
     */
    const TYPING_SECONDS = 90;

    /**
     * Its state in a chat (conversation meta): questions asked, whether the customer has said what
     * they need; 'done' once handed over.
     */
    const META = 'ai_agent';

    protected static $user = null;

    public static function user()
    {
        if (self::$user && self::$user->exists) {
            return self::$user;
        }
        $user = User::where('email', self::EMAIL)->first();
        if (!$user) {
            $user = new User();
            $user->email = self::EMAIL;
            $user->password = \Hash::make(\Str::random(40));
        }
        $user->first_name = 'AI';
        $user->last_name = '';
        $user->type = User::TYPE_ROBOT;
        // Not a person: in no list of users.
        $user->status = User::STATUS_DELETED;
        if ($user->isDirty()) {
            $user->save();
        }

        return self::$user = $user;
    }

    /**
     * Its user's ID, without making the user (null before its first chat).
     */
    public static function userId()
    {
        return self::$user && self::$user->exists ? self::$user->id : User::where('email', self::EMAIL)->value('id');
    }

    /**
     * The chat channels a mailbox has it on (off by default).
     */
    public static function channels(Mailbox $mailbox)
    {
        return array_values(array_intersect(self::CHANNELS, (array) (((array) \Option::get('aiassistant.mailbox_agent_channels', []))[$mailbox->id] ?? [])));
    }

    /**
     * Whether it takes a new conversation: on a channel it's on for, from a person (not an
     * auto-responder or a bounce), nobody assigned, and the AI set up with tokens left today.
     */
    public static function takes(Conversation $conversation, ?Thread $thread = null)
    {
        $mailbox = $conversation->mailbox;
        $key = ChannelMessages::key($conversation);

        return $mailbox && $key && in_array($key, self::channels($mailbox), true)
            && !$conversation->user_id && !$conversation->imported && !$conversation->isSpam()
            && (!$thread || self::fromPerson($conversation, $thread))
            && Settings::isConfigured() && Settings::withinBudget($mailbox);
    }

    /**
     * Not an auto-responder's message or a bounce: nothing to answer.
     */
    protected static function fromPerson(Conversation $conversation, Thread $thread)
    {
        return !$thread->isAutoResponder() && !$thread->isBounce() && !\MailHelper::isAutoReplySubject($conversation->subject);
    }

    public static function holds(Conversation $conversation)
    {
        return $conversation->user_id && (int) $conversation->user_id === (int) self::userId();
    }

    public static function wrote(Thread $thread)
    {
        return $thread->created_by_user_id && (int) $thread->created_by_user_id === (int) self::userId();
    }

    /**
     * Whether people aren't told about something: in a chat it has, or something it did.
     */
    public static function quiet(Conversation $conversation, $caused_by_user_id = null)
    {
        return self::holds($conversation) || ($caused_by_user_id && (int) $caused_by_user_id === (int) self::userId());
    }

    /**
     * Take a new chat: Assigned to it, its first message once the customer has finished writing.
     */
    public static function take(Conversation $conversation)
    {
        $conversation->setUser(self::user()->id);
        $conversation->setMeta(self::META, ['questions' => 0]);
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();
        AiChatAgentReply::dispatch($conversation->id)->delay(now()->addSeconds(self::WAIT));
    }

    /**
     * The customer wrote in a chat it has: its next message (once they've finished writing). An
     * auto-responder's or a bounce gets none (it hands over once its question goes unanswered).
     */
    public static function customerReplied(Conversation $conversation, ?Thread $thread = null)
    {
        if (self::holds($conversation) && empty($conversation->getMeta(self::META, [])['done'])
            && (!$thread || (!$thread->isAutoResponder() && !$thread->isBounce()))
        ) {
            AiChatAgentReply::dispatch($conversation->id)->delay(now()->addSeconds(self::WAIT));
        }
    }

    /**
     * A person replied in a chat it still has: theirs now (with nobody chosen, nobody's).
     */
    public static function personReplied(Conversation $conversation, Thread $thread)
    {
        if (self::holds($conversation) && !self::wrote($thread)) {
            self::finish($conversation);
            $conversation->setUser(null);
            $conversation->save();
            $conversation->mailbox->updateFoldersCounters();
        }
    }

    /**
     * Its next step in a chat it has, for the customer's latest message: a question, or the chat
     * handed to the team. One at a time per chat.
     */
    public static function reply(Conversation $conversation)
    {
        // Its step, and at the end the team's draft.
        $lock = \Cache::lock('ai-agent:'.$conversation->id, Drafts::TIMEOUT * 2 + 60);
        // Busy with the chat: that run answers what came since.
        if (!$lock->get()) {
            return;
        }
        try {
            $answered = self::step($conversation);
        } finally {
            self::typing($conversation, false);
            $lock->release();
        }
        // More from the customer while it thought.
        $conversation->refresh();
        if ($answered && self::holds($conversation) && $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->where('id', '>', $answered)->exists()) {
            self::customerReplied($conversation);
        }
    }

    /**
     * Returns the customer message it answered, if any.
     */
    protected static function step(Conversation $conversation)
    {
        $conversation->refresh();
        $state = (array) $conversation->getMeta(self::META, []);
        $last = self::lastMessage($conversation);
        if (!self::holds($conversation) || !empty($state['done']) || !$last || $last->type != Thread::TYPE_CUSTOMER) {
            return null;
        }
        $mailbox = $conversation->mailbox;
        $questions = (int) ($state['questions'] ?? 0);
        $language = Settings::language($mailbox);
        try {
            if (!Settings::isConfigured() || !Settings::withinBudget($mailbox)) {
                throw new \RuntimeException('The AI is not set up, or the mailbox has no tokens left today.');
            }
            self::typing($conversation);
            $context = ConversationContext::build($conversation, $language, false, null, microtime(true) + Drafts::TIMEOUT);
            // Their language as far as known (a website chat's browser): for a greeting that doesn't tell.
            $customer_language = ChatTranslation::customerLanguage($conversation);
            $prompt = $context['prompt'].($customer_language ? "\n\n".ChatIntake::data('customer_language', Settings::languageName($customer_language).' ('.$customer_language.')') : '');
            $answer = (new ChatIntake($language, $questions, ChannelMessages::key($conversation) == 'email'))->recordFor(Usage::FEATURE_AGENT, $conversation)->withImages(ConversationContext::images($context))->prompt($prompt)->toArray();
            $action = $answer['action'] ?? null;
            $message = trim((string) ($answer['message'] ?? ''));
            if (!in_array($action, ['ask', 'hand_over'], true) || ($action == 'ask' && $message === '')) {
                throw new \UnexpectedValueException('The AI gave no usable answer.');
            }
        } catch (\Throwable $e) {
            Errors::report($e, 'AI agent in conversation #'.$conversation->number.':');
            self::handOver($conversation, '', [], __('The AI couldn’t continue this conversation.'));

            return $last->id;
        }

        // A person took it, or it was handed over, while it thought.
        $conversation->refresh();
        if (!self::holds($conversation) || !empty($conversation->getMeta(self::META, [])['done'])) {
            return $last->id;
        }
        if ($action == 'ask' && $questions < self::MAX_QUESTIONS) {
            $thread = self::send($conversation, $message, $answer);
            $has_request = !empty($answer['has_request']);
            $conversation->setMeta(self::META, ['questions' => $questions + 1, 'has_request' => $has_request], true);
            AiChatAgentSilence::dispatch($conversation->id, $thread->id)->delay(now()->addMinutes($has_request ? self::HAND_OVER_AFTER : self::CLOSE_AFTER));
        } else {
            // What it's about, in the list, instead of a greeting; an email keeps its subject (the
            // customer's, which their replies carry).
            $subject = trim((string) ($answer['subject'] ?? ''));
            if ($subject !== '' && ChannelMessages::key($conversation) != 'email') {
                $conversation->subject = mb_substr($subject, 0, 100);
                $conversation->save();
            }
            // Out of questions, it hands over without its last one.
            self::handOver($conversation, $action == 'hand_over' ? $message : '', $answer);
        }

        return $last->id;
    }

    /**
     * No answer to its question ($thread_id): a chat with a request goes to the team; one without
     * closes, and opens again (still its) when the customer writes.
     */
    public static function silence(Conversation $conversation, $thread_id)
    {
        $state = (array) $conversation->getMeta(self::META, []);
        $last = self::lastMessage($conversation);
        if (!self::holds($conversation) || !empty($state['done']) || !$conversation->isActive() || !$last || $last->id != $thread_id) {
            return;
        }
        if (!empty($state['has_request'])) {
            self::handOver($conversation);
        } else {
            $conversation->changeStatus(Conversation::STATUS_CLOSED, self::user());
        }
    }

    /**
     * Hand the chat to the team: its last message to the customer, a reply drafted for them
     * (draft()), unassigned, and people told as of a new conversation. $answer: Agents\ChatIntake's
     * (the message's translation). $error: why it couldn't go on, a note instead of the draft.
     */
    public static function handOver(Conversation $conversation, $message = '', array $answer = [], $error = '')
    {
        $user = self::user();
        if (trim($message) !== '') {
            self::send($conversation, $message, $answer);
        }
        if ($error !== '') {
            $conversation->createUserThread($user, self::html($error), ['type' => Thread::TYPE_NOTE]);
        } else {
            self::draft($conversation);
        }
        self::finish($conversation);
        $conversation->changeUser(null, $user);
        Subscription::registerEvent(Subscription::EVENT_TYPE_NEW, $conversation, $user->id);
        Subscription::processEvents();
    }

    /**
     * Whether it's writing its next message in a chat (the website chat's window says so).
     */
    public static function isTyping(Conversation $conversation)
    {
        return \Cache::has('ai-agent:typing:'.$conversation->id);
    }

    /**
     * Show the customer it's writing, where their channel can (the website chat's window, Telegram
     * for its first seconds, Matrix), or stop. Only a nicety: its message comes either way.
     */
    public static function typing(Conversation $conversation, $typing = true)
    {
        $key = 'ai-agent:typing:'.$conversation->id;
        if ($typing) {
            \Cache::put($key, true, self::TYPING_SECONDS);
        } elseif (!\Cache::pull($key)) {
            return;
        }
        $mailbox = $conversation->mailbox;
        try {
            $channel = ChannelMessages::key($conversation);
            if ($channel == 'telegram' && $typing && Telegram::isEnabled($mailbox) && ($chat_id = $conversation->customer?->getChannelId(Telegram::CHANNEL))) {
                Telegram::client($mailbox)->sendChatAction($chat_id, 'typing');
            } elseif ($channel == 'matrix') {
                (new \App\Matrix\Outgoing())->typing($conversation, $typing, self::TYPING_SECONDS);
            }
        } catch (\Throwable $e) {
            // Not shown, then.
        }
    }

    /**
     * A reply for the team to start from, as Draft with AI makes it (Drafts::draft()), in the
     * team's language too: whoever opens the conversation finds it (handoverDraft()). Without one,
     * a note says why.
     */
    protected static function draft(Conversation $conversation)
    {
        $mailbox = $conversation->mailbox;
        if (!Settings::enabled('drafts', $mailbox) || !Settings::withinBudget($mailbox)) {
            return;
        }
        $draft_job = new DraftJob();
        $draft_job->conversation_id = $conversation->id;
        $draft_job->user_id = self::user()->id;
        $draft_job->status = DraftJob::STATUS_RUNNING;
        $draft_job->started_at = now();
        $draft_job->save();
        try {
            $draft_job->result = Drafts::draft($conversation, Settings::language($mailbox));
            $draft_job->status = DraftJob::STATUS_COMPLETED;
        } catch (\Throwable $e) {
            $draft_job->status = DraftJob::STATUS_FAILED;
            $draft_job->error_type = get_class($e);
            $draft_job->error_message = __('Could not draft a reply.');
            $draft_job->error_detail = __('ID').': '.Errors::report($e, 'AI agent\'s draft in conversation #'.$conversation->number.':');
        }
        $draft_job->completed_at = now();
        $draft_job->save();
        // None (the sources don't support an answer, or it failed): why, for the team.
        if (trim((string) ($draft_job->result['draft'] ?? '')) === '') {
            $notes = array_filter((array) ($draft_job->result['staff_notes'] ?? []), fn ($note) => trim($note) !== '');
            $conversation->createUserThread(self::user(), self::html(implode("\n\n", array_merge([__('The AI couldn’t draft a reply.')], $notes))), ['type' => Thread::TYPE_NOTE]);
        }
    }

    /**
     * Its draft for the team (draft()), for $user while nobody has replied since and they have no
     * draft of their own in the conversation: the reply panel shows it as Draft with AI's
     * (conversations/partials/ai_draft_panel).
     */
    public static function handoverDraft(Conversation $conversation, $user)
    {
        $user_id = self::userId();
        $draft_job = $user_id ? DraftJob::where('conversation_id', $conversation->id)->where('user_id', $user_id)->orderBy('id', 'desc')->first() : null;
        if (!$draft_job || $draft_job->status != DraftJob::STATUS_COMPLETED || trim((string) ($draft_job->result['draft'] ?? '')) === '') {
            return null;
        }
        $replied = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)
            ->where('created_at', '>=', $draft_job->created_at)->where('created_by_user_id', '!=', $user_id)->exists();
        $own_draft = $conversation->threads()->where('state', Thread::STATE_DRAFT)->where('created_by_user_id', $user->id)->exists();

        return $replied || $own_draft ? null : $draft_job->result;
    }

    protected static function finish(Conversation $conversation)
    {
        $conversation->setMeta(self::META, ['done' => true] + (array) $conversation->getMeta(self::META, []), true);
    }

    /**
     * Its message to the customer, sent at once (no Undo). Written in another language than the
     * team's, its translation ($answer: Agents\ChatIntake's) is shown as a translated reply's is
     * (Drafts::keepTranslation()): the team's version, and the one sent beside it.
     */
    protected static function send(Conversation $conversation, $text, array $answer = [])
    {
        self::typing($conversation, false);
        $conversation->createUserThread(self::user(), self::html($text));
        $thread = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('created_by_user_id', self::user()->id)->orderBy('id', 'desc')->first();
        $translation = trim((string) ($answer['translation'] ?? ''));
        if ($thread && $translation !== '') {
            $language = Settings::language($conversation->mailbox);
            $sent_in = (string) ($answer['language'] ?? '');
            $data = Summaries::data($thread);
            $data['translations'][$language] = mb_substr($translation, 0, 20000);
            $data['written_in'] = $language;
            $data['sent_in'] = Settings::isLanguage($sent_in) ? $sent_in : ChatTranslation::customerLanguage($conversation);
            $thread->ai_assistant = json_encode($data, JSON_UNESCAPED_UNICODE);
            $thread->ai_assistant_updated_at = now();
            $thread->save();
        }

        return $thread;
    }

    /**
     * Plain text as paragraphs.
     */
    protected static function html($text)
    {
        $paragraphs = array_filter(preg_split("/\n\s*\n/", trim(str_replace("\r", '', $text))), fn ($paragraph) => trim($paragraph) !== '');

        return implode('', array_map(fn ($paragraph) => '<p>'.nl2br(e(trim($paragraph)), false).'</p>', $paragraphs));
    }

    /**
     * The chat's latest message, the customer's or the team's.
     */
    protected static function lastMessage(Conversation $conversation)
    {
        return $conversation->threads()->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)->orderBy('id', 'desc')->first();
    }
}

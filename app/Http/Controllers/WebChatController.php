<?php

namespace App\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Misc\ChatConversations;
use App\Thread;
use App\WebChat\Identity;
use App\WebChat\Spam;
use App\WebChat\Visitor;
use App\WebChat\WebChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The website chat's window (/t/w) and the small JSON API it uses (App\WebChat). No session and no
 * cookies: browsers block or split cookies in a frame from another website, so the window keeps a
 * visitor token in its own storage and sends it as a bearer token. Every path is relative to /t/,
 * so a website can serve the folder through its own domain.
 */
class WebChatController extends Controller
{
    /**
     * The chat window: in the website's frame (the script, public/t/t.js), or on its own (the
     * website's "Chat with us" link, the fallback without the script).
     */
    public function window(Request $request)
    {
        $mailbox = WebChat::mailbox($request->widget);
        $settings = $mailbox ? WebChat::settings($mailbox) : null;
        $origin = (string) $request->origin;
        if (!$mailbox || ($origin !== '' && !WebChat::allows($settings, $origin))) {
            // Not this website's: nothing it may show (the script leaves no button behind).
            return response(__('This chat is not available here.'), 404)
                ->header('Content-Security-Policy', "frame-ancestors 'none'");
        }
        $this->locale($request);

        $response = response()->view('webchat/window', [
            'available' => Spam::available($mailbox),
            'mailbox'  => $mailbox,
            'settings' => $settings,
            'team'     => WebChat::teamName($mailbox),
            'origin'   => $origin !== '' ? WebChat::origin($origin) : '',
            'nonce'    => \Helper::cspNonce(),
            'poll'     => ['active' => WebChat::POLL_ACTIVE, 'idle' => WebChat::POLL_IDLE, 'closed' => WebChat::POLL_CLOSED],
        ]);

        // Only the widget's websites may frame it (frame-ancestors makes browsers ignore X-Frame-Options).
        return $response->header('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".\Helper::cspNonce()."' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            // The proof of work's worker (/t/x).
            "worker-src 'self'",
            "frame-ancestors 'self' ".implode(' ', $settings['origins']),
        ]))->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A new visitor: the token the window keeps.
     */
    public function start(Request $request)
    {
        $mailbox = $this->mailbox($request);
        $this->limit('start:'.WebChat::clientIp($request, $mailbox), 30, 3600);
        [, $token] = Visitor::start($mailbox);

        return response()->json(['token' => $token]);
    }

    /**
     * The conversation so far (messages after `after`), how many of the team's are unread, and how
     * soon to ask again.
     */
    public function messages(Request $request)
    {
        $visitor = $this->visitor($request);
        $this->limit('poll:'.$visitor->token, 60, 60);
        $this->locale($request);
        if ($visitor->exists && (!$visitor->last_seen_at || $visitor->last_seen_at->lt(now()->subSeconds(30)))) {
            $visitor->last_seen_at = now();
            $visitor->save();
        }

        $messages = [];
        $unread = 0;
        $cursor = (int) $request->after;
        if ($visitor->exists && ($conversation = $visitor->currentConversation())) {
            foreach ($this->threads($conversation)->where('id', '>', $cursor)->get() as $thread) {
                $messages[] = $this->message($thread);
            }
            $unread = $this->threads($conversation)->where('type', Thread::TYPE_MESSAGE)->where('id', '>', $visitor->read_thread_id)->count();
            // Messages up to the cursor won't change; a reply the team can still undo shows later, with
            // an older ID, so the cursor stays before it.
            $pending = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)
                ->where('created_at', '>', now()->subSeconds(Conversation::UNDO_TIMOUT))->min('id');
            $cursor = max($cursor, $pending ? $pending - 1 : (int) collect($messages)->max('id'));
        }

        return response()->json([
            'messages'     => $messages,
            'unread'       => $unread,
            // The launcher's name with the count, in the visitor's language.
            'unread_label' => trans_choice(':name, :count unread message|:name, :count unread messages', $unread, ['name' => __('Chat with :name', ['name' => WebChat::teamName($visitor->mailbox)])]),
            'cursor'       => $cursor,
            // By name once the website has signed them in.
            'greeting'     => WebChat::greeting($visitor->mailbox, $visitor->customer),
        ] + $this->emailState($visitor));
    }

    /**
     * A message from the visitor: text and files. The first makes them a customer and starts the
     * conversation; later ones continue it while the mailbox's Reopen Window allows.
     */
    public function send(Request $request)
    {
        $visitor = $this->visitor($request);
        $request->validate([
            'body'    => 'nullable|string|max:'.WebChat::MAX_LENGTH,
            'files'   => 'array|max:'.WebChat::MAX_FILES,
            'files.*' => 'file|max:'.(WebChat::MAX_FILE_MB * 1024),
            'page'    => 'nullable|string|max:2048',
        ]);
        $text = trim((string) $request->body);
        $attachments = [];
        foreach ((array) $request->file('files') as $file) {
            $attachments[] = ['file_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'data' => base64_encode($file->get())];
        }
        if ($text === '' && !$attachments) {
            return response()->json(['message' => __('Write a message.')], 422);
        }

        // Someone blocked: taken, and dropped.
        $mailbox = $visitor->mailbox;
        $ip_hash = Spam::ipHash(WebChat::clientIp($request, $mailbox));
        if (Spam::blocked($visitor, $ip_hash)) {
            return response()->json(['message' => ['id' => null, 'kind' => 'visitor', 'author' => '', 'initials' => '', 'body' => $text, 'time' => now()->toIso8601String(), 'files' => []]]);
        }
        // A new chat: while the widget takes them, after a proof of work, harder when it looks like a
        // bot: a first message sent the moment the window loaded, or a field only bots fill in (a
        // password manager might too, so a person still gets through).
        $new_chat = !$visitor->exists || !$visitor->currentConversation();
        if ($new_chat && !Spam::available($mailbox)) {
            return response()->json(['message' => __('Chat isn’t available right now. Try again later.'), 'unavailable' => true], 503);
        }
        if ($new_chat) {
            $fast = ($request->filled('elapsed') && (int) $request->elapsed < Spam::FAST_MS) || $request->filled('website');
            $required = Spam::difficulty($mailbox, $fast ? Spam::difficulty($mailbox) + 4 : 0);
            if (!Spam::solved($request->proof, $mailbox, $required)) {
                return response()->json(['message' => '', 'challenge' => Spam::challenge($mailbox, $required)], 428);
            }
        }
        $kilobytes = (int) ceil(array_sum(array_map(fn ($file) => $file->getSize(), (array) $request->file('files'))) / 1024);
        if ($wait = Spam::allowMessage($visitor, $ip_hash, $kilobytes)) {
            return response()->json(['message' => '', 'wait' => $wait[0], 'kind' => $wait[1]], 429);
        }
        $page = $this->page($request->page);
        if ($page) {
            $visitor->page = $page;
        }

        $body = $text !== '' ? preg_replace('/\r\n?|\n/', '<br>', htmlspecialchars($text)) : htmlspecialchars(implode(', ', array_column($attachments, 'file_name')));
        $request_language = (string) $request->language;
        $received = \DB::transaction(function () use ($visitor, $text, $body, $attachments, $request_language, $ip_hash) {
            $visitor->ip_hash = $ip_hash;
            if (!$visitor->exists) {
                $visitor->save();
            }
            $customer = $visitor->makeCustomer();
            $conversation = $visitor->currentConversation();
            $received = ChatConversations::receive($conversation, $customer, [
                'subject'     => $this->subject($text !== '' ? $text : strip_tags($body)),
                'mailbox_id'  => $visitor->mailbox_id,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'channel'     => WebChat::CHANNEL,
            ], [
                'body'        => $body,
                'attachments' => $attachments,
                // A risky type is renamed, as in customers' emails.
                'attachments_upload_mode' => \Helper::UPLOAD_MODE_BY_CUSTOMER,
            ]);
            if (empty($received['thread'])) {
                return null;
            }
            // Back in the chat after answering by email: the conversation follows them here.
            if ($received['conversation']->channel != WebChat::CHANNEL) {
                $received['conversation']->channel = WebChat::CHANNEL;
                $received['conversation']->save();
            }
            // Their language, until detected in what they write: the browser's first.
            \App\Ai\ChatTranslation::setCustomerLanguageFromBrowser($customer, $request_language);
            $visitor->conversation_id = $received['conversation']->id;
            $visitor->last_seen_at = now();
            $visitor->save();

            return $received;
        });
        if (!$received) {
            return response()->json(['message' => __('Your message could not be sent. Try again.')], 500);
        }
        if ($new_chat) {
            Spam::countNewChat($mailbox);
        }
        \App\Misc\ChatLog::record(WebChat::META, $visitor->mailbox_id, 'receive', 'succeeded', '', ['thread_id' => $received['thread']->id]);

        return response()->json(['message' => $this->message($received['thread']->fresh())]);
    }

    /**
     * A proof-of-work challenge for a new chat (App\WebChat\Spam): the window solves it while the
     * visitor types their first message.
     */
    public function challenge(Request $request)
    {
        $mailbox = $this->mailbox($request);
        $this->limit('challenge:'.WebChat::clientIp($request, $mailbox), 60, 600);

        return response()->json(Spam::challenge($mailbox, min(Spam::MAX_DIFFICULTY, (int) $request->at_least)));
    }

    /**
     * The visitor signed in on the website (App\WebChat\Identity): what they wrote here moves to
     * their account, and with nothing written yet, their latest chat continues. Someone else signing
     * in on the same browser gets a new visitor (its token is returned), so nobody sees another's chat.
     */
    public function identify(Request $request)
    {
        $mailbox = $this->mailbox($request);
        $this->limit('identify:'.WebChat::clientIp($request, $mailbox), 60, 600);
        $identity = Identity::hasSecret($mailbox) ? Identity::verify((string) $request->identity, Identity::secret($mailbox)) : null;
        if (!$identity) {
            return response()->json(['message' => 'The sign-in token is not valid.'], 422);
        }
        $channel_id = Identity::channelId($identity['sub']);
        $visitor = Visitor::findByToken((string) $request->bearerToken(), $mailbox);
        $token = null;
        if (!$visitor || ($visitor->identity !== null && $visitor->identity !== $channel_id)) {
            [$visitor, $token] = Visitor::start($mailbox);
        }
        $visitor->setRelation('mailbox', $mailbox);

        \DB::transaction(function () use ($visitor, $identity, $channel_id, $mailbox, $request) {
            $customer = Identity::customer($identity);
            \App\Ai\ChatTranslation::setCustomerLanguageFromBrowser($customer, (string) $request->language);
            if ($visitor->customer && $visitor->customer->id != $customer->id) {
                $customer->mergeWith($visitor->customer);
            }
            $visitor->customer_id = $customer->id;
            $visitor->identity = $channel_id;
            $visitor->data = $identity['data'] ? json_encode($identity['data']) : null;
            $visitor->unsetRelation('customer');
            if (!$visitor->currentConversation()) {
                $latest = Visitor::where('customer_id', $customer->id)->where('mailbox_id', $mailbox->id)->whereNotNull('conversation_id')
                    ->orderBy('updated_at', 'desc')->value('conversation_id');
                $visitor->conversation_id = $latest ?: ChatConversations::latest($mailbox, $customer, WebChat::CHANNEL)?->id;
                $visitor->unsetRelation('conversation');
                if (!$visitor->currentConversation()) {
                    $visitor->conversation_id = null;
                }
            }
            $visitor->last_seen_at = now();
            $visitor->save();
        });

        return response()->json(['token' => $token]);
    }

    /**
     * "Get the reply by email": a code to the address (App\WebChat\EmailCode).
     */
    public function emailCode(Request $request)
    {
        $visitor = $this->visitor($request);
        $this->limit('email:'.$visitor->token, 10, 600);
        $this->locale($request);
        $error = \App\WebChat\EmailCode::send($visitor, $request->email, WebChat::clientIp($request, $visitor->mailbox));

        return $error ? response()->json(['message' => $error], 422) : response()->json($this->emailState($visitor->fresh()));
    }

    /**
     * The code from the email: the address counts from now on.
     */
    public function confirmEmail(Request $request)
    {
        $visitor = $this->visitor($request);
        $this->limit('confirm:'.$visitor->token, 20, 600);
        $this->locale($request);
        $error = \App\WebChat\EmailCode::confirm($visitor, $request->code);

        return $error ? response()->json(['message' => $error], 422) : response()->json($this->emailState($visitor->fresh()));
    }

    /**
     * Where replies also go (a confirmed or signed-in address), and an address waiting for its code.
     */
    protected function emailState(Visitor $visitor)
    {
        $waiting = \App\WebChat\EmailCode::waiting($visitor);

        return [
            'email'       => WebChat::email($visitor->customer),
            'pending'     => $waiting ? $visitor->pending_email : null,
            'codes_left'  => $waiting ? max(0, \App\WebChat\EmailCode::MAX_CODES - $visitor->codes_sent) : null,
        ];
    }

    /**
     * The visitor has seen the team's messages up to this one.
     */
    public function read(Request $request)
    {
        $visitor = $this->visitor($request);
        $this->limit('read:'.$visitor->token, 60, 60);
        if (!$visitor->exists) {
            return response()->json([]);
        }
        $thread_id = (int) $request->thread_id;
        if ($thread_id > $visitor->read_thread_id && $visitor->conversation_id
            && Thread::where('id', $thread_id)->where('conversation_id', $visitor->conversation_id)->exists()) {
            $visitor->read_thread_id = $thread_id;
            $visitor->save();
        }

        return response()->json([]);
    }

    /**
     * A file in the conversation, by its token (links can't carry the visitor's token).
     */
    public function file(Request $request)
    {
        $attachment = Attachment::find((int) $request->id);
        if (!$attachment || !\Helper::hashEquals($attachment->getToken(), (string) $request->token)
            || !$attachment->thread || !Visitor::where('conversation_id', $attachment->thread->conversation_id)->exists()) {
            abort(404);
        }

        // An image (by its contents too) shows in the browser, as its own type; anything else downloads.
        $type = WebChat::imageType($attachment);
        if ($type && $request->thumbnail && ($path = \App\Misc\AttachmentImages::thumbnail($attachment))) {
            return Attachment::getDisk()->response($path, pathinfo($attachment->file_name, PATHINFO_FILENAME).'.jpg', [
                'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=2592000', 'X-Content-Type-Options' => 'nosniff',
            ], 'inline');
        }
        if ($type) {
            return Attachment::getDisk()->response($attachment->getStorageFilePath(), $attachment->file_name, [
                'Content-Type' => $type, 'Cache-Control' => 'private, max-age=2592000', 'X-Content-Type-Options' => 'nosniff',
            ], 'inline');
        }

        return $attachment->download(false, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The window's styles (s) and script (j), from beside it (/t/), so a proxied website serves them
     * too: FruitUI, Alpine (FruitUI's standalone build: the window has no Livewire) and its own.
     */
    public function asset($name)
    {
        $files = WebChat::assetFiles($name);
        if (!$files) {
            abort(404);
        }
        $contents = implode("\n", array_map(fn ($file) => file_get_contents(public_path($file)), $files));

        return response($contents, 200, [
            'Content-Type'  => $name == 's' ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The visitor's language, of those Tallport has: what the browser asks for.
     */
    protected function locale(Request $request)
    {
        $locales = (array) config('app.locales');
        $preferred = $request->getPreferredLanguage(array_map(fn ($locale) => str_replace('-', '_', $locale), $locales));
        if ($preferred) {
            app()->setLocale(str_replace('_', '-', $preferred));
        }
    }

    protected function mailbox(Request $request)
    {
        $mailbox = WebChat::mailbox($request->widget);
        if (!$mailbox) {
            abort(404);
        }
        // The window's origin: Tallport's, or an allowed website's that serves /t/ itself.
        $origin = $request->headers->get('Origin');
        if ($origin && !WebChat::allows(WebChat::settings($mailbox), $origin)) {
            abort(403);
        }

        return $mailbox;
    }

    protected function visitor(Request $request)
    {
        $mailbox = $this->mailbox($request);
        $visitor = Visitor::findByToken((string) $request->bearerToken(), $mailbox);
        if (!$visitor) {
            abort(401);
        }

        return $visitor->setRelation('mailbox', $mailbox);
    }

    /**
     * At most $max per $seconds, or 429.
     */
    protected function limit($key, $max, $seconds)
    {
        if (RateLimiter::tooManyAttempts('web-chat:'.$key, $max)) {
            abort(429);
        }
        RateLimiter::hit('web-chat:'.$key, $seconds);
    }

    /**
     * What the visitor sees of the conversation: their messages and the team's replies (not notes,
     * drafts or what the team can still undo).
     */
    protected function threads(Conversation $conversation)
    {
        return $conversation->threads()
            ->where('state', Thread::STATE_PUBLISHED)
            ->where(function ($query) {
                $query->where('type', Thread::TYPE_CUSTOMER)
                    ->orWhere(function ($query) {
                        $query->where('type', Thread::TYPE_MESSAGE)->where('created_at', '<=', now()->subSeconds(Conversation::UNDO_TIMOUT));
                    });
            })
            ->orderBy('id');
    }

    protected function message(Thread $thread)
    {
        $team = $thread->type == Thread::TYPE_MESSAGE;
        $author = $team ? ($thread->created_by_user ? $thread->created_by_user->getFirstName() : WebChat::teamName($thread->conversation->mailbox)) : '';

        return [
            'id'       => $thread->id,
            'kind'     => $team ? 'team' : 'visitor',
            'author'   => $author,
            'initials' => $team ? mb_strtoupper(mb_substr($author, 0, 1)) : '',
            'body'     => \App\Misc\ChatDelivery::plainText($thread),
            // The team's: when the visitor could see it, once Undo had passed.
            'time'     => ($team ? $thread->created_at->copy()->addSeconds(Conversation::UNDO_TIMOUT) : $thread->created_at)->toIso8601String(),
            'files'    => $thread->attachments->map(function ($attachment) {
                $url = 'f?'.http_build_query(['id' => $attachment->id, 'token' => $attachment->getToken()]);
                $image = WebChat::imageType($attachment) !== null;

                return [
                    'id'        => $attachment->id,
                    'name'      => $attachment->file_name,
                    'size'      => $attachment->getSizeName(),
                    'url'       => $url,
                    // An image: a thumbnail that opens it in the browser.
                    'thumbnail' => $image ? $url.'&thumbnail=1' : null,
                ];
            })->values()->all(),
        ];
    }

    protected function subject($text)
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));

        return mb_strlen($plain) > Conversation::SUBJECT_LENGTH ? mb_substr($plain, 0, Conversation::SUBJECT_LENGTH - 1).'…' : $plain;
    }

    /**
     * The website's page the visitor is on: an http(s) address without its fragment.
     */
    protected function page($url)
    {
        $url = preg_replace('/#.*$/', '', trim((string) $url));

        return preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 2048) : null;
    }
}

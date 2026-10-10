<?php

namespace App\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Email;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUserChanged;
use App\Events\UserAddedNote;
use App\Events\UserCreatedConversation;
use App\Events\UserCreatedConversationDraft;
use App\Events\UserCreatedThreadDraft;
use App\Events\UserReplied;
use App\Folder;
use App\Job;
use App\Mailbox;
use App\MailboxUser;
use App\SendLog;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Validator;

class ConversationsController extends Controller
{
    const PREV_CONVERSATIONS_LIMIT = Conversation::PREV_CONVERSATIONS_LIMIT;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * View conversation.
     */
    public function view(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('viewCached', $conversation);

        $mailbox = $conversation->mailbox;
        $customer = $conversation->customer_cached;
        $user = auth()->user();

        // To let other parts of the app easily access.
        \Helper::setGlobalEntity('conversation', $conversation);
        \Helper::setGlobalEntity('mailbox', $mailbox);

        if ($user->isAdmin()) {
            $mailbox->fetchUserSettings($user->id);
        }

        // Seen: its notifications read. Pages fetched by wire:navigate may be prefetched on
        // hover and never shown: they say they were seen themselves (the viewed ajax action).
        $prefetchable = (bool) $request->header('X-Livewire-Navigate');
        if (!$prefetchable) {
            self::markNotificationsRead($conversation, $user, $request->mark_as_read);
            \App\ConversationRead::markRead($conversation->id, $user);
        }

        // Its folder follows from the mailbox the user works in (App\Misc\Sidebar),
        // which stays as it is. Not in the URL (older links have it).
        if ($request->query('folder_id') !== null && !$request->attributes->get('folder_opened')) {
            \Session::reflash();

            return redirect()->away($conversation->url(null, null, $request->except('folder_id')));
        }
        $folder = \App\Misc\Sidebar::conversationFolder($conversation, $user) ?: new Folder();

        // Opening the folder again comes back here (openFolder()).
        if (!$prefetchable && $folder->id) {
            session()->put('folder_conversation.'.$folder->id, $conversation->id);
        }

        $template = 'conversations/view';
        if ($conversation->state == Conversation::STATE_DRAFT) {
            $template = 'conversations/create';
        }

        // Other users see who is viewing (polycast conview), modules that it's opened.
        \App\Events\RealtimeConvView::dispatchSelf($conversation->id, $user, false);
        // The customer's photo looked up online, when there's none (any more).
        \App\Misc\CustomerPhotos::request($conversation->customer, $conversation->customer_email);
        \Eventy::action('conversation.view.start', $conversation, $request);

        // The folder's conversations beside the conversation (split view).
        $list = null;
        if ($template == 'conversations/view' && !$request->input('print')) {
            $list = self::folderList($folder, $user, $request->input('list_page'));
        }

        return view($template, array_merge(self::pageData($conversation, $folder, $user), ['list' => $list]));
    }

    /**
     * What a conversation's page shows (conversations/view, App\Livewire\ConversationPane):
     * its threads, customer, recipients, aliases, viewers and previous conversations.
     * Computed once per request for a conversation and user.
     */
    public static function pageData($conversation, $folder, $user)
    {
        $memo = request()->attributes->get('conversation_page_data', []);
        $key = $conversation->id.'-'.($folder ? $folder->id : '').'-'.$user->id;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $mailbox = $conversation->mailbox;
        $customer = $conversation->customer_cached;

        //$after_send = $conversation->mailbox->getUserSettings($user->id)->after_send;
        $after_send = $user->afterSend();

        // Detect customers and emails to which user can reply
        $to_customers = [];
        // Add all customer emails
        $customer_emails = [];
        $distinct_emails = [];

        // Add emails of customers from whom there were replies in the conversation
        $prev_customers_emails = [];
        if ($conversation->customer_email) {
            $prev_customers_emails = Thread::select('from', 'customer_id')
                ->where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_CUSTOMER)
                ->where('from', '<>', $conversation->customer_email)
                ->groupBy(['from', 'customer_id'])
                ->get();
        }

        foreach ($prev_customers_emails as $prev_customer) {
            if (!in_array($prev_customer->from, $distinct_emails) && $prev_customer->customer && $prev_customer->from) {
                $to_customers[] = [
                    'customer' => $prev_customer->customer,
                    'email'    => $prev_customer->from,
                ];
                $distinct_emails[] = $prev_customer->from;
            }
        }

        // Add customer email(s) if there more than one or if there are other emails in threads.
        if ($customer) {
            $customer_emails = $customer->emails;
        }
        // This is tricky case - when customer_email is different from the
        // currently selected customer.
        // 1. Email has been received from a customer.
        // 2. Customer has been changed.
        // 3. Reply has been sent to the original customer email.
        if ($conversation->customer_email 
            && count($customer_emails)
            && !in_array($conversation->customer_email, $customer_emails->pluck('email')->toArray())
        ) {
            $extra_customer_added = false;
            foreach ($to_customers as $to_customer) {
                if ($to_customer['email'] == $conversation->customer_email) {
                    $extra_customer_added = true;
                    break;
                }
            }
            if (!$extra_customer_added) {
                // Get customer by email.
                $extra_customer = Customer::getByEmail($conversation->customer_email);
                if ($extra_customer) {
                    $to_customers[] = [
                        'customer' => $extra_customer,
                        'email'    => $conversation->customer_email,
                    ];
                }
            }
        }
        if (count($customer_emails) > 1 || count($to_customers)) {
            foreach ($customer_emails as $customer_email) {
                $to_customers[] = [
                    'customer' => $customer,
                    'email'    => $customer_email->email,
                ];
                $distinct_emails[] = $customer_email->email;
            }
        }

        // Exclude mailbox emails from $to_customers.
        $mailbox_emails = $mailbox->getEmails();
        foreach ($to_customers as $key => $to_customer) {
            if (in_array($to_customer['email'], $mailbox_emails)) {
                unset($to_customers[$key]);
            }
        }

        $threads = $conversation->threads()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();

        // Get To for new conversation.
        $new_conv_to = [];
        if (empty($threads[0]) || empty($threads[0]->to)) {
            // Before new conversation To field was stored in $conversation->customer_email.
            $emails = Conversation::sanitizeEmails($conversation->customer_email);
            // Get customers info for emails.
            if (count($emails)) {
                $new_conv_to = Customer::emailsToCustomers($emails);
            }
        } else {
            $new_conv_to = Customer::emailsToCustomers($threads[0]->getToArray());
        }

        if (empty($customer) && count($new_conv_to) == 1) {
            $customer = Customer::getByEmail(array_key_first($new_conv_to));
        }

        // Previous conversations
        $prev_conversations = [];
        if ($customer) {
            $prev_conversations = $mailbox->conversations()
                                    ->where('customer_id', $customer->id)
                                    ->where('id', '<>', $conversation->id)
                                    ->where('status', '!=', Conversation::STATUS_SPAM)
                                    ->where('state', Conversation::STATE_PUBLISHED)
                                    //->limit(self::PREV_CONVERSATIONS_LIMIT)
                                    ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                                    ->paginate(self::PREV_CONVERSATIONS_LIMIT);
        }

        // CC.
        $exclude_array = $conversation->getExcludeArray($mailbox);
        $cc = $conversation->getCcArray($exclude_array);

        // If last reply came from customer who was mentioned in CC before,
        // we need to add this customer as CC.
        // https://github.com/freescout-helpdesk/freescout/issues/3613
        foreach ($threads as $thread) {
            if ($thread->isUserMessage() && !$thread->isDraft()) {
                break;
            }
            if ($thread->isCustomerMessage()) {
                if ($thread->customer_id != $conversation->customer_id) {
                    $cc[] = $thread->from;
                }
                break;
            }
        }

        // Get data for creating a phone conversation.
        $name = [];
        $phone = '';
        $to_email = [];
        if ($customer) {
            if ($customer->getFullName()) {
                $name = [$customer->id => $customer->getFullName()];
            }
            $last_phone = array_last($customer->getPhones());
            if (!empty($last_phone)) {
                $phone = $last_phone['value'];
            }

            if ($conversation->customer_email) {
                $customer_email = $conversation->customer_email;
            } else {
                $customer_email = $customer->getMainEmail();
            }
            if ($customer_email) {
                $to_email = [$customer_email];
            }
        }

        // Notify other users that current user is viewing conversation.
        // Eventually notification data will be saved in polycast_events table and processes
        // in JS in users browsers.

        // $notification = new \App\Notifications\UserViewingConversationNotification(
        //     $conversation, $user, false
        // );

        // This broadcasts to specific users.
        // \Notification::send($mailbox->usersHavingAccess(), $notification);

        // Notification is sent to all via public channel: conview
        // If we send notification to each user, applications having thouthans of users
        // will be overloaded.
        // // https://laravel.com/docs/5.5/broadcasting#broadcasting-events

        // Get viewers.
        $viewers = [];
        $conv_view = \Cache::get('conv_view');
        if ($conv_view && !empty($conv_view[$conversation->id])) {
            $viewing_users = User::whereIn('id', array_keys($conv_view[$conversation->id]))->get();
            foreach ($viewing_users as $viewer) {
                if (isset($conv_view[$conversation->id][$viewer->id]['r']) && $viewer->id != $user->id) {
                    $viewers[] = [
                        'user'     => $viewer,
                        'replying' => (int)$conv_view[$conversation->id][$viewer->id]['r'],
                    ];
                }
            }
            // Show replying first.
            usort($viewers, function ($a, $b) {
                return $b['replying'] <=> $a['replying'];
            });
        }

        $is_following = $conversation->isUserFollowing($user->id);


        // Mailbox aliases.
        $from_aliases = $conversation->mailbox->getAliases(true, true);
        $from_alias = '';

        if (count($from_aliases) == 1) {
            $from_aliases = [];
        }
        if ($conversation->isDraft() && !empty($threads[0])) {
            $from_alias = $threads[0]->from ?? '';
        }
        if (count($from_aliases) && !$from_alias) {
            // Preset the last alias used.
            $check_initial_thread = true;
            foreach ($threads as $thread) {
                if ($thread->isUserMessage() && !$thread->isDraft()) {
                    $check_initial_thread = false;
                    if ($thread->from) {
                        $from_alias = $thread->from;
                    }
                    break;
                }
            }
            // Maybe the first email has been sent to some mailbox alias.
            if (!$from_alias && $check_initial_thread) {
                $initial_thread = $threads->last();
                if ($initial_thread && $initial_thread->isCustomerMessage()) {
                    $initial_recipients = $initial_thread->getToArray();
                    $initial_recipients = array_merge($initial_recipients, $initial_thread->getCcArray());
                    foreach ($initial_recipients as $initial_recipient) {
                        foreach ($from_aliases as $from_alias_email => $dummy) {
                            if ($initial_recipient == $from_alias_email) {
                                $from_alias = $from_alias_email;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        $memo[$key] = [
            'conversation'       => $conversation,
            'mailbox'            => $mailbox,
            'customer'           => $customer,
            'threads'            => \Eventy::filter('conversation.view.threads', $threads),
            'folder'             => $folder,
            'folders'            => $conversation->mailbox->getAssesibleFolders(),
            'after_send'         => $after_send,
            'to'                 => $new_conv_to,
            'to_customers'       => $to_customers,
            'prev_conversations' => $prev_conversations,
            'cc'                 => $cc,
            'bcc'                => [], //$conversation->getBccArray($exclude_array),
            // Data for creating a phone conversation.
            'name'               => $name,
            'phone'              => $phone,
            'to_email'           => $to_email,
            'viewers'            => $viewers,
            'is_following'       => $is_following,
            'from_aliases'       => $from_aliases,
            'from_alias'         => $from_alias,
        ];
        request()->attributes->set('conversation_page_data', $memo);

        return $memo[$key];
    }

    /**
     * The user's notifications about a conversation (or the one opened from) read.
     */
    public static function markNotificationsRead($conversation, $user, $notification_id = null)
    {
        if ($notification_id) {
            // Notification IDs are UUIDs (PostgreSQL refuses anything else in the query).
            if (\Str::isUuid($notification_id)) {
                $user->unreadNotifications()->where('id', $notification_id)->update(['read_at' => now()]);
            }
            $user->clearWebsiteNotificationsCache();

            return;
        }
        // Built by hand rather than unreadNotifications(), to use the index.
        $marked = $user->morphMany(\Illuminate\Notifications\DatabaseNotification::class, 'notifiable')
            ->where('conversation_id', $conversation->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        if ($marked) {
            $user->clearWebsiteNotificationsCache();
        }
    }

    /**
     * A folder opens at a conversation beside its list: the one last opened from it in
     * this session, or its first. Null for an empty folder, a later page, or a narrow
     * window, where the list goes first (the tallport_narrow cookie, public/js/tallport.js).
     *
     * The conversation is shown at the folder's URL, without a redirect (one request
     * instead of two); the page then shows its own URL (data-page-url, tallport.js).
     */
    /**
     * The conversation a folder opens at (openFolder()), for opening it in place
     * (App\Livewire\ConversationOpens); null when it has none. Once per request.
     */
    public static function folderConversationId($folder, $user)
    {
        return app(\App\Misc\ConversationListQuery::class)->folderConversationId($folder, $user);
    }

    public static function openFolder(Request $request, $folder, $query, $conversations)
    {
        if (!count($conversations) || $request->filled('page') || $request->cookie('tallport_narrow')) {
            return null;
        }
        $conversation_id = session()->get('folder_conversation.'.$folder->id);
        if (!$conversation_id || !(clone $query)->where('conversations.id', $conversation_id)->exists()) {
            $conversation_id = $conversations->first()->id;
        }

        $request->attributes->set('folder_opened', true);

        return app(self::class)->view($request, $conversation_id);
    }

    /**
     * A folder's conversations, for the list beside an open conversation.
     */
    public static function folderList($folder, $user, $page = null)
    {
        return app(\App\Misc\ConversationListQuery::class)->folderList($folder, $user, $page);
    }

    /**
     * New conversation.
     */
    public function create(Request $request, $mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('view', $mailbox);

        $subject = trim($request->get('subject') ?? '');

        $conversation = new Conversation();
        $conversation->body = '';
        $conversation->mailbox = $mailbox;

        $folder = $mailbox->folders()->where('type', Folder::TYPE_DRAFTS)->first();

        // todo: use $user->mailboxSettings()
        $after_send = auth()->user()->afterSend();

        // Create conversation from thread
        $thread = null;
        $attachments = [];
        if (!empty($request->from_thread_id)) {
            $orig_thread = Thread::find($request->from_thread_id);
            if ($orig_thread && auth()->user()->can('view', $orig_thread->conversation)) {
                $subject = $orig_thread->conversation->subject;
                $subject = preg_replace('/^Fwd:/i', 'Re: ', $subject);

                $thread = new Thread();
                $thread->body = $orig_thread->body;
                // If this is a forwarded message, try to fetch From
                preg_match_all("/From:[^<\n]+<([^<\n]+)>/m", html_entity_decode(strip_tags($thread->body)), $m);

                if (!empty($m[1])) {
                    foreach ($m[1] as $value) {
                        if (\MailHelper::validateEmail($value)) {
                            $thread->to = json_encode([$value]);
                            break;
                        }
                    }
                }

                // Clone attachments.
                $orig_attachments = Attachment::where('thread_id', $orig_thread->id)->get();

                if (count($orig_attachments)) {
                    $conversation->has_attachments = true;
                    $thread->has_attachments = true;
                    foreach ($orig_attachments as $attachment) {
                        $attachments[] = $attachment->duplicate();
                    }
                }
            }
        }

        $to = [];

        // Prefill some values.
        if ($request->get('to')) {
            $prefill_to_emails = explode(',', $request->get('to'));
            foreach ($prefill_to_emails as $prefill_to_email) {
                $prefill_to_email = \App\Email::sanitizeEmail($prefill_to_email);
                if ($prefill_to_email) {
                    $to[$prefill_to_email] = $prefill_to_email;
                }
            }
        }

        if ($request->get('body') && !$thread) {
            $thread = new Thread();
            $thread->body = \Helper::stripDangerousTags($request->get('body'));
        }

        $conversation->subject = $subject;

        return view('conversations/create', [
            'conversation' => $conversation,
            'thread'       => $thread,
            'mailbox'      => $mailbox,
            'folder'       => $folder,
            'folders'      => $mailbox->getAssesibleFolders(),
            'after_send'   => $after_send,
            'to'           => $to,
            'from_aliases' => $mailbox->getAliases(true, true),
            'attachments'  => $attachments,
        ]);
    }

    /**
     * Clone conversation.
     */
    public function cloneConversation(Request $request, $mailbox_id, $from_thread_id)
    {
        abort_unless($request->isMethod('post'), 405);

        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('view', $mailbox);

        if (!\Helper::hashEquals(csrf_token(), $request->input('_token'))) {
            throw new \Illuminate\Session\TokenMismatchException;
        }

        if (!empty($from_thread_id)) {
            $orig_thread = Thread::find($from_thread_id);
            
            if ($orig_thread) {
                $orign_conv = $orig_thread->conversation;
                $this->authorize('view', $orign_conv);

		        // $thread = $orig_thread->replicate();
		        // $thread->id = '';
		        // $thread->message_id .= ".clone".crc32(mktime());
		        // $thread->status = Thread::STATUS_ACTIVE;
		        // $thread->conversation_id = $conversation->id;
		        // $thread->save();


                $now = date('Y-m-d H:i:s');

                $conversation = new Conversation();
                $conversation->type = $orign_conv->type;
                $conversation->subject = $orign_conv->subject;
                $conversation->mailbox_id = $orign_conv->mailbox_id;
                $conversation->preview = '';
                // Preset source_via here to avoid error in PostgreSQL.
                $conversation->source_via = $orign_conv->source_via;
                $conversation->source_type = $orign_conv->source_type;
                $conversation->customer_id = $orign_conv->customer_id;
                $conversation->customer_email = $orign_conv->customer->getMainEmail();
                $conversation->status = Conversation::STATUS_ACTIVE;
                $conversation->state = Conversation::STATE_PUBLISHED;
                $conversation->cc = $orig_thread->cc;
                $conversation->bcc = $orig_thread->bcc;
                // Set assignee
                $conversation->user_id = $orign_conv->user_id;
                $conversation->updateFolder();
                $conversation->save();
                
                $thread = Thread::createExtended([
                        'conversation_id' => $orig_thread->conversation_id,
                        'user_id' => $orig_thread->user_id,
                        'type' => $orig_thread->type,
                        'status' => $conversation->status,
                        'state' => $conversation->state,
                        'body' => $orig_thread->body,
                        'headers' => $orig_thread->headers,
                        'from' => $orig_thread->from,
                        'to' => $orig_thread->to,
                        'cc' => $orig_thread->getCcArray(),
                        'bcc' => $orig_thread->getBccArray(),
                        //'attachments' => $attachments,
                        'has_attachments' => $orig_thread->has_attachments,
                        'message_id' => "clone".crc32(microtime()).'-'.$orig_thread->message_id,
                        'source_via' => $orig_thread->source_via,
                        'source_type' => $orig_thread->source_type,
                        'customer_id' => $orig_thread->customer_id,
                        'created_by_customer_id' => $orig_thread->created_by_customer_id,
                    ],
                    $conversation
                );
                
                // Clone attachments.
                $attachments = Attachment::where('thread_id', $orig_thread->id)->get();
                foreach ($attachments as $attachment) {
                    $attachment->duplicate($thread->id);
                }

                return redirect()->away($conversation->url());
            } else {
                return redirect()->away(\App\Misc\Sidebar::folderUrl(null, $mailbox->id));
            }
        } else {
            return redirect()->away(\App\Misc\Sidebar::folderUrl(null, $mailbox->id));
        }
    }

    /**
     * Conversation draft.
     */
    // public function draft($id)
    // {
    //     $conversation = Conversation::findOrFail($id);

    //     $this->authorize('view', $conversation);

    //     return view('conversations/create', [
    //         'conversation' => $conversation,
    //         'mailbox'      => $conversation->mailbox,
    //         'folder'       => $conversation->folder,
    //         'folders'      => $conversation->mailbox->getAssesibleFolders(),
    //     ]);
    // }

    /**
     * Conversations ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        switch ($request->action) {

            // A conversation page shown after wire:navigate (public/js/conversations.js):
            // what opening it does (ConversationsController::view()), now that it's seen.
            case 'viewed':
                $conversation = Conversation::find($request->conversation_id);
                if (!$conversation || !$user->can('view', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                self::markNotificationsRead($conversation, $user, $request->mark_as_read);
                \App\ConversationRead::markRead($conversation->id, $user);
                // openFolder() checks it's still in the folder.
                if ($shown_in = \App\Misc\Sidebar::conversationFolder($conversation, $user)) {
                    session()->put('folder_conversation.'.$shown_in->id, $conversation->id);
                }
                $response['status'] = 'success';
                break;

            // Change conversation user
            case 'conversation_change_user':
                $response = array_merge($response, \App\Misc\ConversationActions::changeUser(Conversation::find($request->conversation_id), $request->user_id, $user, $request));
                break;

            // Change conversation status
            case 'conversation_change_status':
                $response = array_merge($response, \App\Misc\ConversationActions::changeStatus(Conversation::find($request->conversation_id), $request->status, $user, $request));
                break;

            // Send reply, new conversation, add note or forward
            case 'send_reply':
                $response = $this->ajaxSendReply($request, $response, $user);
                break;

            // Save draft (automatically or by click) of a new conversation or reply.
            case 'save_draft':
                $response = $this->ajaxSaveDraft($request, $response, $user);
                break;

            // Discard draft (from new conversation, from reply or conversation)
            case 'discard_draft':
                $response = $this->ajaxDiscardDraft($request, $response, $user);
                break;

            // Save draft (automatically or by click)
            case 'load_draft':
                $thread = Thread::find($request->thread_id);
                if (!$thread) {
                    $response['msg'] = __('Thread not found');
                } elseif ($thread->state != Thread::STATE_DRAFT) {
                    $response['msg'] = __('Thread is not in a draft state');
                } else {
                    if (!$user->can('view', $thread->conversation)) {
                        $response['msg'] = __('Not enough permissions');
                    }
                }

                if (!$response['msg']) {

                    // Build attachments list.
                    $attachments = [];
                    foreach ($thread->attachments as $attachment) {
                        $attachments[] = [
                            'id'   => encrypt($attachment->id),
                            'name' => $attachment->file_name,
                            'size' => $attachment->size,
                            'url'  => $attachment->url(),
                        ];
                    }

                    $response['data'] = [
                        'thread_id'   => $thread->id,
                        'from_alias'  => $thread->from,
                        'to'          => $thread->getToFirst(),
                        'cc'          => $thread->getCcArray(),
                        'bcc'         => $thread->getBccArray(),
                        'body'        => $thread->body,
                        'is_forward'  => (int)$thread->isForward(),
                        'attachments' => $attachments,
                    ];
                    $response['status'] = 'success';
                }
                break;

            // Load attachments from all threads in conversation
            // when forwarding or creating a new conversation.
            case 'load_attachments':
                $conversation = Conversation::find($request->conversation_id);
                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                } else {
                    if (!$user->can('view', $conversation)) {
                        $response['msg'] = __('Not enough permissions');
                    }
                }

                if (!$response['msg']) {
                    // Build attachments list.
                    $attachments = [];

                    if ($conversation->has_attachments) {
                        foreach ($conversation->threads as $thread) {
                            if ($thread->has_attachments && (!$thread->isDraft() || count($conversation->threads) == 1)) {
                                foreach ($thread->attachments as $attachment) {
                                    if ($request->is_forwarding == 'true') {
                                        $attachment_copy = $attachment->duplicate();
                                    } else {
                                        $attachment_copy = $attachment;
                                    }

                                    if ($attachment_copy) {
                                        $attachments[] = [
                                            'id'   => encrypt($attachment_copy->id),
                                            'name' => $attachment_copy->file_name,
                                            'size' => $attachment_copy->size,
                                            'url'  => $attachment_copy->url(),
                                        ];
                                    }
                                }
                            }
                        }
                    }

                    $response['data'] = [
                        'attachments' => $attachments,
                    ];
                    $response['status'] = 'success';
                }
                break;


            // Conversations navigation
            case 'conversations_pagination':
                $list = $this->listConversations($request, $user);

                if (!empty($list['msg'])) {
                    $response['msg'] = $list['msg'];
                    break;
                }

                $response['status'] = 'success';
                $response['html'] = view('conversations/conversations_table', [
                    'folder'               => $list['folder'],
                    'conversations'        => $list['conversations'],
                    'params'               => $request->params ?? [],
                    'conversations_filter' => $list['conversations_filter'],
                ])->render();
                break;

            // Change conversation customer
            case 'conversation_change_customer':
                $conversation = Conversation::find($request->conversation_id);
                $customer_email = $request->customer_email;
                $target_customer = Customer::getByEmail($request->customer_email);

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                } elseif (!$target_customer) {
                    // The change customer dialog creates new customers first.
                    $response['msg'] = __('Customer not found');
                }
                if (!$response['msg'] && !$user->can('update', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (!$response['msg'] && !$conversation->mailbox->userHasAccess($user->id)) {
                    $response['msg'] = __('Not enough permissions');
                }

                // Allow to change customer when user creates a customer
                // while changing conversation's customer
                // and APP_LIMIT_USER_CUSTOMER_VISIBILITY is enabled.
                if ($target_customer && session()->get('user_created_customer') == $target_customer->id) {
                    session()->forget('user_created_customer');
                } else {
                    if (!$response['msg'] && $target_customer && !$user->can('view', $target_customer)) {
                        $response['msg'] = __('Not enough permissions');
                    }
                }

                if (!$response['msg']) {
                    $result = $conversation->changeCustomer($customer_email, null, $user);

                    if ($result) {
                        $response['status'] = 'success';
                        \Session::flash('flash_success_floating', __('Customer changed'));
                    }
                }

                break;

            // Star/unstar conversation
            case 'star_conversation':
                $conversation = Conversation::find($request->conversation_id);
                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                } elseif (!$user->can('view', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }

                if (!$response['msg']) {
                    if ($request->sub_action == 'star') {
                        $conversation->star($user);
                    } else {
                        $conversation->unstar($user);
                    }
                    $response['status'] = 'success';
                }
                break;

            // Delete conversation (move to DELETED folder)
            case 'delete_conversation':
                $response = array_merge($response, \App\Misc\ConversationActions::delete(Conversation::find($request->conversation_id), $user));
                break;

            // Delete conversation forever
            case 'delete_conversation_forever':
                $response = array_merge($response, \App\Misc\ConversationActions::delete(Conversation::find($request->conversation_id), $user, true));
                break;

            // Restore conversation
            case 'restore_conversation':
                $response = array_merge($response, \App\Misc\ConversationActions::restore(Conversation::find($request->conversation_id), $user));
                break;

            // Load data to edit thread.
            case 'load_edit_thread':
                $thread = Thread::find($request->thread_id);
                if (!$thread) {
                    $response['msg'] = __('Thread not found');
                } elseif (!$user->can('edit', $thread)) {
                    $response['msg'] = __('Not enough permissions');
                }

                if (!$response['msg']) {
                    $thread->body = \Helper::stripDangerousTags($thread->body);

                    $data = [
                        'thread' => $thread,
                    ];
                    $response['html'] = \View::make('conversations/partials/edit_thread')->with($data)->render();

                    $response['status'] = 'success';
                }
                break;

            // Load data to edit thread.
            case 'save_edit_thread':
                $response = array_merge($response, \App\Misc\ConversationActions::editThread(Thread::find($request->thread_id), $request->body, $user));
                break;

            // Delete thread (note).
            case 'delete_thread':
                $response = array_merge($response, \App\Misc\ConversationActions::deleteNote(Thread::find($request->thread_id), $user));
                break;

            // Change conversations user
            case 'bulk_conversation_change_user':
                Conversation::bulkChangeUser($request->conversation_id, $request->user_id, $user);

                $response['status'] = 'success';
                // Flash
                \Session::flash('flash_success_floating', __('Assignee updated'));

                $response['msg'] = __('Assignee updated');
                break;

            // Change conversations status
            case 'bulk_conversation_change_status':
                if (!in_array((int) $request->status, array_keys(Conversation::$statuses))) {
                    $response['msg'] = __('Incorrect status');
                }

                if (!$response['msg']) {
                    Conversation::bulkChangeStatus($request->conversation_id, $request->status, $user);

                    $response['status'] = 'success';
                    // Flash
                    \Session::flash('flash_success_floating', __('Status updated'));

                    $response['msg'] = __('Status updated');
                }
                break;

            // Delete converations.
            case 'bulk_delete_conversation':
                // At first, check if this user is able to delete conversations
                if (!auth()->user()->isAdmin() && !auth()->user()->hasPermission(\App\User::PERM_DELETE_CONVERSATIONS)) {
                    $response['msg'] = __('Not enough permissions');
                    //\Session::flash('flash_success_floating', __('Conversations deleted'));

                    return \Response::json($response);
                }

                Conversation::bulkDelete($request->conversation_id, $user);

                $response['status'] = 'success';
                \Session::flash('flash_success_floating', __('Conversations deleted'));
                break;

            // Delete converations in a specific folder.
            case 'empty_folder':
                // At first, check if this user is able to delete conversations
                if (!$user->isAdmin() && !$user->hasPermission(\App\User::PERM_DELETE_CONVERSATIONS)) {
                    $response['msg'] = __('Not enough permissions');
                    return \Response::json($response);
                }

                // All Mailboxes: the folder of every mailbox of the user.
                if (\App\Misc\AllMailboxes::isAllMailboxes($request->mailbox_id)) {
                    if (\App\Misc\AllMailboxes::emptyFolder($user, $request->folder_id)) {
                        $response['status'] = 'success';
                    } else {
                        $response['msg'] = __('Folder not found');
                    }

                    return \Response::json($response);
                }

                // Check access to the mailbox.
                $folder = Folder::find($request->folder_id ?? '');

                if (!$folder) {
                    $response['msg'] = __('Folder not found');
                    return \Response::json($response);
                }
                if (!in_array($folder->type, [Folder::TYPE_SPAM, Folder::TYPE_DELETED])) {
                    $response['msg'] = __('Folder not found');
                    return \Response::json($response);
                }
                if (!$folder->mailbox->userHasAccess($user->id)) {
                    $response['msg'] = __('Not enough permissions');
                    return \Response::json($response);
                }

                $response = \Eventy::filter('conversations.empty_folder', $response, 
                    $request->mailbox_id,
                    $request->folder_id
                );

                if (empty($response['processed'])) {

                    if (!$user->isAdmin() && $folder->mailbox && !$folder->mailbox->userHasAccess($user->id)) {
                        $response['msg'] = __('Not enough permissions');
                    }

                    if (!$response['msg']) {
                        // Do not allow users who can see only assigned conversations
                        // delete any conversations in the folder.
                        // https://github.com/freescout-help-desk/freescout/security/advisories/GHSA-6mhr-m8m9-6q6h
                        if (!$user->isAdmin() && $user->canSeeOnlyAssignedConversations()) {
                            // User can see (and selete) only assigned conversations. 
                            $conversation_ids = Conversation::where('folder_id', $folder->id)
                                ->get()
                                ->filter(function ($conversation) use ($user) {
                                    return $conversation->isAssignedToUser($user);
                                })
                                ->pluck('id')
                                ->toArray();
                        } else {
                            $conversation_ids = Conversation::where('folder_id', $folder->id)->pluck('id')->toArray();
                        }

                        Conversation::deleteConversationsForever($conversation_ids);
                        if ($folder->mailbox) {
                            Conversation::clearStarredByUserCache($user->id, $folder->mailbox_id);
                            $folder->mailbox->updateFoldersCounters();
                        } else {
                            $folder->updateCounters();
                        }
                    }
                }

                $response['status'] = 'success';
                \Session::flash('flash_success_floating', __('Conversations deleted'));
                break;

            // Move conversation to another mailbox.
            case 'conversation_move':
                $conversation = Conversation::find($request->conversation_id);

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                }
                if (!$response['msg'] && !$user->can('update', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (!$response['msg'] && !$conversation->mailbox->userHasAccess($user->id)) {
                    $response['msg'] = __('Not enough permissions');
                }

                $mailbox = null;
                if (!$response['msg']) {
                    if (!empty($request->mailbox_email)) {
                        $mailbox = Mailbox::where('email', $request->mailbox_email)->first();
                    } else {
                        $mailbox = Mailbox::find($request->mailbox_id);
                    }

                    if (!$mailbox) {
                        $response['msg'] = __('Mailbox not found');
                    }
                }

                if (!$response['msg']) {
                    $prev_mailbox_id = $conversation->mailbox_id;
                    $prev_folder = $conversation->folder;

                    $conversation->moveToMailbox($mailbox, $user);

                    // If user does not have access to the new mailbox,
                    // back to the folder it was in.
                    if (!$mailbox->userHasAccess($user->id)) {
                        $response['redirect_url'] = \App\Misc\Sidebar::folderUrl($prev_folder, $prev_mailbox_id);
                    }

                    $response['status'] = 'success';
                    \Session::flash('flash_success_floating', __('Conversation moved'));
                }

                break;

            // Merge conversations
            case 'conversation_merge':
                $conversation = Conversation::find($request->conversation_id);

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                }
                if (!$response['msg'] && !$user->can('view', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }

                if (!$response['msg'] && !empty($request->merge_conversation_id) && is_array($request->merge_conversation_id)) {
                    
                    // Problems with any of the conversations are all reported.
                    $errors = [];

                    foreach ($request->merge_conversation_id as $merge_conversation_id) {
                        $merge_conversation = Conversation::find($merge_conversation_id);

                        $error = '';
                        if (!$merge_conversation) {
                            $error = __('Conversation not found');
                        } elseif ($merge_conversation->id == $conversation->id) {
                            $error = __('A conversation can not be merged with itself.');
                        } elseif (!$user->can('view', $merge_conversation)) {
                            $error = __('Not enough permissions').': #'.$merge_conversation->number;
                        }

                        if ($error) {
                            $errors[] = $error;
                        } else {
                            $conversation->mergeConversations($merge_conversation, $user);

                            if ($response['status'] != 'success') {
                                \Session::flash('flash_success_floating', __('Conversations merged'));
                            }
                            $response['status'] = 'success';
                        }
                    }

                    $response['msg'] = implode(' ', array_unique($errors));
                }

                break;

            // Follow conversation
            case 'follow':
            case 'unfollow':
                $response = array_merge($response, \App\Misc\ConversationActions::follow(Conversation::find($request->conversation_id), $user, $request->action == 'follow'));
                break;

            case 'update_subject':
                $response = array_merge($response, \App\Misc\ConversationActions::changeSubject(Conversation::find($request->conversation_id), $request->value, $user));
                break;

            case 'merge_search':
                $conversation = Conversation::where(Conversation::numberFieldName(), $request->number)->first();

                if (!$conversation || $conversation->id == ($request->cur_conv_id ?? '')) {
                    $response['msg'] = __('Conversation not found');
                }
                if (!$response['msg'] && !$user->can('view', $conversation)) {
                    $response['msg'] = __('Conversation not found');
                }

                if (!$response['msg']) {
                    $response['html'] = \View::make('conversations/partials/merge_search_result')->with([
                            'conversation' => $conversation,
                        ])->render();
                    $response['conversation'] = [
                        'id'      => $conversation->id,
                        'number'  => $conversation->number,
                        'subject' => $conversation->getSubject(),
                        'url'     => $conversation->url(),
                    ];
                    $response['status'] = 'success';
                }

                break;

            case 'retry_send':
                $response = array_merge($response, \App\Misc\ConversationActions::retrySend(Thread::find($request->thread_id), $user));
                break;

            case 'load_customer_info':
                $customer = Customer::getByEmail($request->customer_email);

                if ($customer) {

                    if (!$user->can('view', $customer)) {
                        $response['msg'] = __('Not enough permissions');
                        break;
                    }

                    $mailbox = Mailbox::find($request->mailbox_id);

                    if (!$mailbox || !$mailbox->userHasAccess($user->id)) {
                        $response['msg'] = __('Not enough permissions');
                        break;
                    }

                    // Previous conversations
                    $prev_conversations = [];

                    if ($mailbox && $mailbox->userHasAccess($user->id)) {
                        $conversation_id = (int)$request->conversation_id ?? 0;

                        $prev_conversations = $mailbox->conversations()
                            ->where('customer_id', $customer->id)
                            ->where('id', '<>', $conversation_id)
                            ->where('status', '!=', Conversation::STATUS_SPAM)
                            ->where('state', Conversation::STATE_PUBLISHED)
                            //->limit(self::PREV_CONVERSATIONS_LIMIT)
                            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                            ->paginate(self::PREV_CONVERSATIONS_LIMIT);
                    }

                    $response['html'] = \View::make('conversations/partials/customer_sidebar')->with([
                            'customer' => $customer,
                            'prev_conversations' => $prev_conversations,
                        ])->render();
                    $response['status'] = 'success';
                } else {
                    $response['msg'] = 'Customer not found';
                }
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }

    /**
     * Conversations ajax controller.
     */
    public function ajaxHtml(Request $request)
    {
        switch ($request->action) {
            case 'send_log':
                return $this->ajaxHtmlSendLog();
            case 'show_original':
                return $this->ajaxHtmlShowOriginal();
            case 'change_customer':
                return $this->ajaxHtmlChangeCustomer();
            case 'move_conv':
                return $this->ajaxHtmlMoveConv();
            case 'merge_conv':
                return $this->ajaxHtmlMergeConv();
        }

        abort(404);
    }

    /**
     * Send log.
     */
    public function ajaxHtmlSendLog()
    {
        $thread_id = request()->input('thread_id');
        if (!$thread_id) {
            abort(404);
        }

        $thread = Thread::find($thread_id);
        if (!$thread) {
            abort(404);
        }

        $user = auth()->user();

        if (!$user->can('view', $thread->conversation)) {
            abort(403);
        }

        // Get send log
        $log_records = SendLog::where('thread_id', $thread_id)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();

        $customers_log = [];
        $users_log = [];
        foreach ($log_records as $log_record) {
            if ($log_record->user_id) {
                $users_log[$log_record->email][] = $log_record;
            } else {
                $customers_log[$log_record->email][] = $log_record;
            }
        }

        return view('conversations/ajax_html/send_log', [
            'customers_log' => $customers_log,
            'users_log'     => $users_log,
        ]);
    }

    /**
     * Show original message headers.
     */
    public function ajaxHtmlShowOriginal()
    {
        $thread_id = request()->input('thread_id');
        if (!$thread_id) {
            abort(404);
        }

        $thread = Thread::find($thread_id);
        if (!$thread) {
            abort(404);
        }

        $user = auth()->user();

        if (!$user->can('view', $thread->conversation)) {
            abort(403);
        }

        $fetched = true;
        $body_preview = $thread->body;
        $source = $thread->getBodyOriginal();
        // The email as it came in (App\Incoming\RawSources): its body, and the whole
        // email as the source.
        $raw = \App\Incoming\RawSources::get($thread);

        if ($thread->isCustomerMessage()) {
            $fetched = false;
            if ($raw !== null) {
                try {
                    $message = \App\Incoming\Parser::parse($raw);
                    $body_preview = $message->htmlBody() ?: nl2br(e((string) $message->textBody()));
                    $source = $raw;
                    $fetched = true;
                } catch (\Throwable $e) {
                    // Shown from the database below.
                }
            }
        }

        return view('conversations/ajax_html/show_original', [
            'thread' => $thread,
            'body_preview' => $body_preview,
            'source' => $source,
            'fetched' => $fetched,
            'raw_kept' => $raw !== null,
        ]);
    }

    /**
     * Show Original's Download .eml: the email as it came in (App\Incoming\RawSources).
     */
    public function originalEml($thread_id)
    {
        $thread = Thread::findOrFail($thread_id);
        if (!auth()->user()->can('view', $thread->conversation)) {
            abort(403);
        }
        $raw = \App\Incoming\RawSources::get($thread);
        if ($raw === null) {
            abort(404);
        }

        return response($raw, 200, [
            'Content-Type'        => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="message-'.$thread->id.'.eml"',
        ]);
    }

    /**
     * Change conversation customer.
     */
    public function ajaxHtmlChangeCustomer()
    {
        $conversation_id = request()->input('conversation_id');
        if (!$conversation_id) {
            abort(404);
        }

        $conversation = Conversation::find($conversation_id);
        if (!$conversation) {
            abort(404);
        }

        $user = auth()->user();

        if (!$user->can('view', $conversation)) {
            abort(403);
        }

        return view('conversations/ajax_html/change_customer', [
            'conversation' => $conversation,
        ]);
    }

    /**
     * Move conversation to other mailbox.
     */
    public function ajaxHtmlMoveConv()
    {
        $conversation_id = request()->input('conversation_id');
        if (!$conversation_id) {
            abort(404);
        }

        $conversation = Conversation::find($conversation_id);
        if (!$conversation) {
            abort(404);
        }

        $user = auth()->user();

        if (!$user->can('view', $conversation)) {
            abort(403);
        }

        $mailboxes = \Eventy::filter('conversations.move_conv.mailboxes', $user->mailboxesCanView());

        return view('conversations/ajax_html/move_conv', [
            'conversation' => $conversation,
            'mailboxes'    => $mailboxes,
        ]);
    }

    /**
     * Merge conversations.
     */
    public function ajaxHtmlMergeConv()
    {
        $conversation_id = request()->input('conversation_id');
        if (!$conversation_id) {
            abort(404);
        }

        $conversation = Conversation::find($conversation_id);
        if (!$conversation) {
            abort(404);
        }

        $user = auth()->user();

        if (!$user->can('view', $conversation)) {
            \Helper::denyAccess();
        }

        $prev_conversations = [];

        if ($conversation->customer_id) {
            $prev_conversations = $conversation->mailbox->conversations()
                                    ->where('customer_id', $conversation->customer_id)
                                    ->where('id', '<>', $conversation->id)
                                    ->where('status', '!=', Conversation::STATUS_SPAM)
                                    ->where('state', Conversation::STATE_PUBLISHED)
                                    ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                                    ->paginate(500);
        }

        return view('conversations/ajax_html/merge_conv', [
            'conversation' => $conversation,
            'prev_conversations' => $prev_conversations,
            'prev_conversation_ids' => $prev_conversations ? collect($prev_conversations->items())->pluck('id')->map('strval') : [],

        ]);
    }

    /**
     * Get redirect URL after performing an action.
     */
    public function getRedirectUrl($request, $conversation, $user)
    {
        return \App\Misc\ConversationActions::redirectUrl($request, $conversation, $user);
    }

    /**
     * Upload files and images.
     */
    public function upload(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        if (!$user) {
            $response['msg'] = __('Please login to upload file');
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid() || !$request->file) {
            $response['msg'] = __('Error occurred uploading file');
        }

        if (!$response['msg']) {
            $embedded = true;

            if (!empty($request->attach) && (int) $request->attach) {
                $embedded = false;
            }

            $attachment = Attachment::create(
                $request->file->getClientOriginalName(),
                $request->file->getMimeType(),
                null,
                '',
                $request->file,
                $embedded,
                null,
                $user->id
            );

            if ($attachment) {
                $response['status'] = 'success';
                $response['url'] = $attachment->url();
                $response['attachment_id'] = encrypt($attachment->id);
            } else {
                $response['msg'] = __('Error occurred uploading file');
            }
        }

        return \Response::json($response);
    }

    /**
     * Send a reply, create a conversation, add a note or forward (send_reply).
     */
    public function ajaxSendReply(Request $request, $response, $user)
    {
        return app(\App\Misc\ConversationReplies::class)->sendReply($request->all(), $user, $response, $request);
    }


    /**
     * Save a draft of a reply, note, forward or new conversation (save_draft).
     */
    public function ajaxSaveDraft(Request $request, $response, $user)
    {
        return app(\App\Misc\ConversationReplies::class)->saveDraft($request->all(), $user, $response, $request);
    }

    /**
     * Discard a draft (discard_draft).
     */
    public function ajaxDiscardDraft(Request $request, $response, $user)
    {
        return app(\App\Misc\ConversationReplies::class)->discardDraft($request->all(), $user, $response, $request);
    }
    /**
     * The conversations of a list: a folder's, or those the filter finds
     * (search results, a customer's conversations). Returns the folder,
     * the conversations and the filter, or an error message.
     */
    public function listConversations(Request $request, $user)
    {
        return app(\App\Misc\ConversationListQuery::class)->listConversations($request->all(), $user, $request);
    }

    /**
     * Search.
     */
    public function search(Request $request)
    {
        $user = auth()->user();
        $conversations = [];
        $customers = [];

        $mode = $this->getSearchMode($request);

        // Search query
        $q = $this->getSearchQuery($request);

        // Filters.
        $filters = $this->getSearchFilters($request);
        $filters_data = [];
        // Modify filters is needed.
        if (!empty($filters['customer'])) {
            // Get customer name.
            $filters_data['customer'] = Customer::find($filters['customer']);
        }
        //$filters = \Eventy::filter('search.filters', $filters, $filters_data, $mode, $q);
        if ($user->canSeeOnlyAssignedConversations()) {
            $filters['assigned'] = $user->id;
        }

        // Remember recent query.
        $recent_search_queries = session('recent_search_queries') ?? [];
        if ($q && !in_array($q, $recent_search_queries)) {
            array_unshift($recent_search_queries, $q);
            $recent_search_queries = array_slice($recent_search_queries, 0, 4);
            session()->put('recent_search_queries', $recent_search_queries);
        }

        $conversations = [];

        if (\Eventy::filter('search.is_needed', true, 'conversations')) {
            // If search string starts with # - try to find conversation by number.
            if (\Str::startsWith($q, '#')) {
                $conv_number = ltrim($q, '#');
                if (is_numeric($conv_number)) {
                    $conversation = Conversation::where(Conversation::numberFieldName(), $conv_number)->first();
                    if ($conversation && $user->can('view', $conversation)) {
                        $conversations[] = $conversation;
                    }
                }
            }

            if (!count($conversations)) {
                $conversations = $this->searchQuery($user, $q, $filters);
            }
        }

        // Jump to the conversation if searching by conversation number.
        if (count($conversations) == 1 
            && ($conversations[0]->number == $q || $conversations[0]->number == ltrim($q, '#'))
            && empty($filters)
            && !$request->x_embed
        ) {
            return redirect()->away($conversations[0]->url($conversations[0]->folder_id));
        }

        $customers = $this->searchCustomers($request, $user);

        // Dummy folder
        $folder = $this->getSearchFolder($conversations);

        // List of available filters.
        if ($mode == Conversation::SEARCH_MODE_CONV) {
            $filters_list = \Eventy::filter('search.filters_list', Conversation::$search_filters, $mode, $filters, $q);
        } else {
            $filters_list = \Eventy::filter('search.filters_list_customers', Customer::$search_filters, $mode, $filters, $q);
        }

        $mailboxes = \Cache::remember('search_filter_mailboxes_'.$user->id, 5 * 60, function () use ($user) {
            return $user->mailboxesCanView();
        });
        $users = \Cache::remember('search_filter_users_'.$user->id, 5 * 60, function () use ($user, $mailboxes) {
            return \Eventy::filter('search.assignees', $user->whichUsersCanView($mailboxes), $user, $mailboxes);
        });
        $search_mailbox = null;
        if (isset($filters['mailbox'])) {
            $mailbox_id = (int)$filters['mailbox'];
            if ($mailbox_id && in_array($mailbox_id, $mailboxes->pluck('id')->toArray())) {
                foreach ($mailboxes as $mailbox_item) {
                    if ($mailbox_item->id == $mailbox_id) {
                        $search_mailbox = $mailbox_item;
                        break;
                    }
                }
            }
        } elseif (count($mailboxes) == 1) {
            $search_mailbox = $mailboxes[0];
        }

        return view('conversations/search', [
            'folder'        => $folder,
            'q'             => $request->q,
            'filters'       => $filters,
            'filters_list'  => $filters_list,
            'filters_data'  => $filters_data,
            //'filters_list_all'  => $filters_list_all,
            'mode'          => $mode,
            'conversations' => $conversations,
            'customers'     => $customers,
            'recent'        => session('recent_search_queries'),
            'users'         => $users,
            'mailboxes'     => $mailboxes,
            'search_mailbox'  => $search_mailbox,
        ]);
    }

    /**
     * Search conversations.
     */
    public function getSearchMode($request)
    {
        return app(\App\Misc\ConversationListQuery::class)->getSearchMode($request);
    }

    /**
     * Search conversations.
     */
    public function searchQuery($user, $q, $filters)
    {
        return app(\App\Misc\ConversationListQuery::class)->searchQuery($user, $q, $filters, request());
    }

    /**
     * Get and format search query.
     */
    public function getSearchQuery($request)
    {
        return app(\App\Misc\ConversationListQuery::class)->getSearchQuery($request);
    }

    /**
     * Get and format search filters.
     */
    public function getSearchFilters($request)
    {
        return app(\App\Misc\ConversationListQuery::class)->getSearchFilters($request);
    }

    /**
     * Search conversations.
     */
    public function searchCustomers($request, $user)
    {
        $limited_visibility = config('app.limit_user_customer_visibility') && !$user->isAdmin();

        // Get IDs of mailboxes to which user has access
        $mailbox_ids = $user->mailboxesIdsCanView();

        // Filters
        $filters = $this->getSearchFilters($request);

        // Search query
        $q = $this->getSearchQuery($request);

        // We need to use aggregate function for email to avoid "Grouping error" error in PostgreSQL.
        $query_customers = Customer::select(['customers.*', \DB::raw('MAX('.\DB::getTablePrefix().'emails.email)')])
            ->groupby('customers.id')
            ->leftJoin('emails', function ($join) {
                $join->on('customers.id', '=', 'emails.customer_id');
            });
        // Every word somewhere (like is case insensitive).
        foreach (preg_split('/\s+/u', $q) ?: [''] as $word) {
            $like = '%'.mb_strtolower($word).'%';
            $query_customers->where(function ($query) use ($like, $word) {
                $like_op = 'like';
                if (\Helper::isPgSql()) {
                    $like_op = 'ilike';
                }

                $query->where('customers.first_name', $like_op, $like)
                    ->orWhere('customers.last_name', $like_op, $like)
                    ->orWhere(!\Helper::isMySql() ? \DB::raw('('.\DB::getTablePrefix().'customers.first_name || \' \' || '.\DB::getTablePrefix().'customers.last_name)') : \DB::raw('CONCAT('.\DB::getTablePrefix().'customers.first_name, " ", '.\DB::getTablePrefix().'customers.last_name)'), $like_op, $like)
                    ->orWhere('customers.company', $like_op, $like)
                    ->orWhere('customers.job_title', $like_op, $like)
                    ->orWhere('customers.websites', $like_op, $like)
                    ->orWhere('customers.social_profiles', $like_op, $like)
                    ->orWhere('customers.address', $like_op, $like)
                    ->orWhere('customers.city', $like_op, $like)
                    ->orWhere('customers.state', $like_op, $like)
                    ->orWhere('customers.zip', $like_op, $like)
                    ->orWhere('emails.email', $like_op, $like);

                $phone_numeric = \Helper::phoneToNumeric($word);

                if ($phone_numeric) {
                    $query->orWhere('customers.phones', $like_op, '%"'.$phone_numeric.'"%');
                }

                // A Nostr key (npub).
                if (str_starts_with(strtolower($word), 'npub1') && ($pubkey = \App\Nostr\Keys::toHex($word))) {
                    $query->orWhereIn('customers.id', \App\Nostr\CustomerKey::where('pubkey', $pubkey)->select('customer_id'));
                }
            });
        }

        if (!empty($filters['mailbox']) && in_array($filters['mailbox'], $mailbox_ids)) {
            $query_customers->join('conversations', function ($join) use ($filters) {
                $join->on('conversations.customer_id', '=', 'customers.id');
                //$join->on('conversations.mailbox_id', '=', $filters['mailbox']);
            });
            $query_customers->where('conversations.mailbox_id', '=', $filters['mailbox']);
        } elseif ($limited_visibility) {
            // Force only mailboxes the user has access to.
            $query_customers->join('conversations', function ($join) use ($filters) {
                $join->on('conversations.customer_id', '=', 'customers.id');
            });
            $query_customers->whereIn('conversations.mailbox_id', $mailbox_ids);
        }

        $query_customers = \Eventy::filter('search.customers.apply_filters', $query_customers, $filters, $q);

        return $query_customers->paginate(50);
    }

    /**
     * Get dummy folder for search.
     */
    public function getSearchFolder($conversations)
    {
        $folder = new Folder();
        $folder->type = Folder::TYPE_ASSIGNED;
        // todo: use select([\DB::raw('SQL_CALC_FOUND_ROWS *')]) to count records
        //$folder->total_count = $conversations->count();

        return $folder;
    }

    /**
     * Filter conversations according to the request.
     */
    public function conversationsFilterQuery($request, $user)
    {
        return app(\App\Misc\ConversationListQuery::class)->conversationsFilterQuery($request, $user);
    }

    /**
     * Process attachments on reply, new conversation, saving draft and forwarding.
     */
    public function processReplyAttachments($request, $thread_id = null, $delete_removed = true)
    {
        return app(\App\Misc\ConversationReplies::class)->processReplyAttachments($request, $thread_id, $delete_removed);
    }

    public function decodeAttachmentsIds($attachments_list)
    {
        return app(\App\Misc\ConversationReplies::class)->decodeAttachmentsIds($attachments_list);
    }

    /**
     * Undo reply.
     */
    public function undoReply(Request $request, $thread_id)
    {
        abort_unless($request->isMethod('post'), 405);

        if (!\Helper::hashEquals(csrf_token(), $request->input('_token'))) {
            throw new \Illuminate\Session\TokenMismatchException;
        }

        $thread = Thread::findOrFail($thread_id);

        if (!$thread) {
            abort(404);
        }

        $conversation = $thread->conversation;

        if ($thread->created_by_user_id != \Auth::id()) {
            \Session::flash('flash_error_floating', __('Sending can not be undone'));
            return redirect()->away($conversation->url($conversation->folder_id));
        }

        $this->authorize('view', $conversation);

        // Check undo timeout; Nostr replies are sent right away.
        if ((int) $thread->created_at->diffInSeconds(now(), true) > Conversation::UNDO_TIMOUT
            || ($thread->type == Thread::TYPE_MESSAGE && (\App\Nostr\Nostr::isNostr($conversation) || \App\Matrix\Matrix::isMatrix($conversation)))
        ) {
            \Session::flash('flash_error_floating', __('Sending can not be undone'));
            return redirect()->away($conversation->url($conversation->folder_id));
        }

        // Convert reply into draft
        $thread->state = Thread::STATE_DRAFT;
        $thread->save();

        // https://github.com/freescout-helpdesk/freescout/issues/3300
        // Cancel all SendReplyToCustomer jobs for this thread.
        $jobs_to_cancel = \App\Job::pending('emails', 'App\Jobs\SendReplyToCustomer');

        foreach ($jobs_to_cancel as $job) {
            $job_thread = $job->getCommandLastThread();
            if ($job_thread && $job_thread->id == $thread->id) {
                $job->cancel();
            }
        }
        // A Telegram reply is sent right away: delete it from the chat.
        if ($conversation->channel == \App\Telegram\Telegram::CHANNEL) {
            \App\Jobs\SendReplyToTelegram::undo($thread);
        }

        // Get penultimate reply
        $last_thread = $conversation->threads()
            ->where('id', '<>', $thread->id)
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->first();

        $folder_id = $conversation->folder_id;

        // Restore conversation data from penultimate thread
        if ($last_thread) {
            $conversation->setCc($last_thread->cc);
            $conversation->setBcc($last_thread->bcc);
            $conversation->last_reply_at = $last_thread->created_at;
            $conversation->last_reply_from = $last_thread->source_via;
            $conversation->user_updated_at = date('Y-m-d H:i:s');
        }
        if ($thread->first) {
            // This was a new conversation, move it to drafts
            $conversation->state = Thread::STATE_DRAFT;

            // Add a record to the conversation_folder table.
            $conversation->addToFolder(Folder::TYPE_DRAFTS);

            $conversation->updateFolder();
            $conversation->mailbox->updateFoldersCounters();
            $folder_id = null;
        }
        $conversation->save();

        // If forwarding has been undone, we need to remove newly created conversation.
        // No need to remove notifications, as they won't work if conversation does not exist.
        if ($thread->isForward()) {
            $forwarded_conversation = $thread->getForwardChildConversation();
            if ($forwarded_conversation) {
                $forwarded_conversation->threads()->delete();
                // todo: maybe perform soft delete of the conversation.
                $forwarded_conversation->delete();
            }
        }

        Conversation::updatePreview($conversation->id);

        return redirect()->away($conversation->url($folder_id, null, ['show_draft' => $thread->id]));
    }

    /**
     * Find or create customer when creating a Phone conversation.
     */
    public function processPhoneCustomer($request, $user)
    {
        return app(\App\Misc\ConversationReplies::class)->processPhoneCustomer($request, $user);
    }

    public function getUndoTimeout($can_undo)
    {
        return app(\App\Misc\ConversationReplies::class)->getUndoTimeout($can_undo);
    }
}

<?php

namespace App\Misc;

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
use App\Mailbox;
use App\MailboxUser;
use App\Thread;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Validator;

/**
 * Sending replies and creating, saving or discarding conversation drafts.
 * HTTP and Livewire pass the same fields and actor to these operations.
 */
class ConversationReplies
{
    public function sendReply($input, $user, $response = ['status' => 'error', 'msg' => ''], $context = null)
    {
        return $this->sendReplyRequest($this->requestFor($input, $context), $response, $user);
    }

    public function saveDraft($input, $user, $response = ['status' => 'error', 'msg' => ''], $context = null)
    {
        return $this->saveDraftRequest($this->requestFor($input, $context), $response, $user);
    }

    public function discardDraft($input, $user, $response = ['status' => 'error', 'msg' => ''], $context = null)
    {
        return $this->discardDraftRequest($this->requestFor($input, $context), $response, $user);
    }

    private function requestFor($input, $context)
    {
        if ($context) {
            return $context;
        }

        $request = Request::createFrom(request());
        $request->query->replace([]);
        $request->setJson(new InputBag());
        $request->replace($input);

        return $request;
    }

    public function getRedirectUrl($request, $conversation, $user)
    {
        return ConversationActions::redirectUrl($request, $conversation, $user);
    }

    private function sendReplyRequest(Request $request, $response, $user)
    {
        $mailbox = Mailbox::findOrFail($request->mailbox_id);

        if (!$response['msg'] && !$user->can('view', $mailbox)) {
            $response['msg'] = __('Not enough permissions');
        }

        $conversation = null;
        if (!$response['msg'] && !empty($request->conversation_id)) {
            $conversation = Conversation::find($request->conversation_id);
            if ($conversation && !$user->can('view', $conversation)) {
                $response['msg'] = __('Not enough permissions');
            }
        }
        $new = false;
        if (empty($request->conversation_id)) {
            $new = true;
        }

        $is_note = false;
        if (!empty($request->is_note)) {
            $is_note = true;
        }

        // Conversation type.
        $type = Conversation::TYPE_EMAIL;
        if (!empty($request->type)) {
            $type = (int)$request->type;
        } elseif ($conversation) {
            $type = $conversation->type;
        }

        $is_phone = false;
        if ($type == Conversation::TYPE_PHONE) {
            $is_phone = true;
        }

        $is_custom = false;
        if ($type == Conversation::TYPE_CUSTOM) {
            $is_custom = true;
        }

        $is_create = false;
        if (!empty($request->is_create)) {
            //if ($new || ($from_draft && $conversation->threads_count == 1)) {
            $is_create = $request->is_create;
        }

        $is_forward = false;
        if (!empty($request->subtype) && (int)$request->subtype == Thread::SUBTYPE_FORWARD) {
            $is_forward = true;
        }

        $is_multiple = false;
        if (!empty($request->multiple_conversations)) {
            $is_multiple = true;
        }

        // If reply is being created from draft, there is already thread created
        $thread = null;
        $from_draft = false;
        if (( ! $is_note || $is_phone || $is_custom ) && ! $response['msg'] && ! empty($request->thread_id)) {
            $thread = Thread::find($request->thread_id);
            if ($thread && (!$conversation || $thread->conversation_id != $conversation->id)) {
                $response['msg'] = __('Incorrect thread');
            } else {
                $from_draft = true;
            }
        }

        // Validate form
        if (!$response['msg']) {
            if ($new) {
                if ($type == Conversation::TYPE_EMAIL) {
                    $validator = Validator::make($request->all(), [
                        'to'       => 'required|array',
                        'subject'  => 'required|string|max:998',
                        'body'     => 'required|string',
                        'cc'       => 'nullable|array',
                        'bcc'      => 'nullable|array',
                    ]);
                } elseif ($type === Conversation::TYPE_PHONE) {
                    // Phone conversation.
                    $validator = Validator::make($request->all(), [
                        'name'     => 'required|string',
                        'subject'  => 'required|string|max:998',
                        'body'     => 'required|string',
                        'phone'    => 'nullable|string',
                        'to_email' => 'nullable|string',
                    ]);
                } elseif ($type === Conversation::TYPE_CUSTOM) {
                    $validation_rules = \Eventy::filter('conversation.custom.validation_rules', [
                        'body' => 'required|string',
                        'cc'   => 'nullable|array',
                        'bcc'  => 'nullable|array',
                    ], $request);
                    $validator        = Validator::make($request->all(), $validation_rules);
                }
            } else {
                $validator = Validator::make($request->all(), [
                    'body'     => 'required|string',
                    'cc'       => 'nullable|array',
                    'bcc'      => 'nullable|array',
                ]);
            }

            if ($validator->fails()) {
                foreach ($validator->errors()->getMessages() as $errors) {
                    foreach ($errors as $field => $message) {
                        $response['msg'] .= $message.' ';
                    }
                }
            }
        }

        $body = $request->body;

        // List of emails.
        $to_array = [];
        if ($is_forward) {
            $to_array = Conversation::sanitizeEmails($request->to_email);
        } else {
            $to_array = Conversation::sanitizeEmails($request->to);
        }
        // Check To
        if (! $response['msg'] && $new && ! $is_phone && ! $is_custom) {
            if (!$to_array) {
                $response['msg'] .= __('Incorrect recipients');
            }
        }

        // Check max. message size.
        if (!$response['msg'] && !$is_note) {

            $max_message_size = (int)config('app.max_message_size');
            if ($max_message_size) {
                // Todo: take into account conversation history.
                $message_size = mb_strlen($body, '8bit');

                // Calculate attachments size.
                $attachments_ids = array_merge($request->attachments ?? [], $request->embeds ?? []);
                $attachments_ids = $this->decodeAttachmentsIds($attachments_ids);

                if (count($attachments_ids)) {
                    $attachments_query = Attachment::select('size')->whereIn('id', $attachments_ids);
                    // Skip embedded images.
                    if (!\Eventy::filter('attachments.embedded.check_size', false)) {
                        $attachments_query->where('embedded', false);
                    }
                    foreach ($attachments_query->get() as $attachment) {
                        $message_size += (int)$attachment->size;
                    }
                }

                if ($message_size*1.37 > $max_message_size*1024*1024) {
                    $response['msg'] = __('Message is too large — :info. Please shorten your message or remove some attachments.', ['info' => __('Max. Message Size').': '.$max_message_size.' MB']);
                }
            }
        }

        if (!$response['msg'] && $request->submission_key
            && (!is_string($request->submission_key) || strlen($request->submission_key) != 36 || !\Illuminate\Support\Str::isUuid($request->submission_key))
        ) {
            $response['msg'] = __('Error occurred. Please try again later.');
        }

        if (!$response['msg']) {

            $saved_files = [];
            $removed_files = [];
            $created_attachments = [];
            $deferred_events = [];
            \DB::beginTransaction();
            try {
                if ($conversation) {
                    $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                }
                if ($thread) {
                    $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
                    if ($thread->state == Thread::STATE_PUBLISHED) {
                        \DB::rollBack();

                        return $this->alreadySubmittedReply($request, $response, $user, $thread);
                    }
                }
                if ($request->submission_key) {
                    $submitted = Thread::where('submission_key', $request->submission_key)->first();
                    if ($submitted) {
                        \DB::rollBack();

                        return $this->alreadySubmittedReply($request, $response, $user, $submitted);
                    }
                }

                // https://github.com/freescout-helpdesk/freescout/issues/3057
                $body = Thread::replaceBase64ImagesWithAttachments($body, null, $created_attachments);
                foreach ($created_attachments as $attachment) {
                    if (!$attachment->fileExists()) {
                        throw new \RuntimeException('Could not store embedded reply image.');
                    }
                }

                // Get attachments info
                // Delete removed attachments.
                $attachments_info = $this->processReplyAttachments($request, $thread->id ?? null, false);
                if ($attachments_info['attachments_to_remove']) {
                    $removed_attachments = Attachment::whereIn('id', $attachments_info['attachments_to_remove'])->get();
                    $removed_files = $removed_attachments->map(function ($attachment) {
                        return $attachment->getStorageFilePath();
                    })->all();
                    Attachment::whereIn('id', $attachments_info['attachments_to_remove'])->delete();
                }
                foreach ($attachments_info['attachments'] as $attachment_id) {
                    $attachment = Attachment::find($attachment_id);
                    if (!$attachment || !$attachment->fileExists()) {
                        throw new \RuntimeException('Reply attachment file is missing.');
                    }
                }

                // Determine redirect.
                // Must be done before updating current conversation's status or assignee.
                // Redirect URL for new no saved yet conversation is determined below.
                if (!$new) {
                    $response['redirect_url'] = $this->getRedirectUrl($request, $conversation, $user);
                }

                // Conversation
                $now = date('Y-m-d H:i:s');
                $status_changed = false;
                $user_changed = false;
                // Chat conversations in chat mode can not be undone.
                $can_undo = true;

                $request_status = (int)$request->status;

                if ($new) {
                    // New conversation
                    $conversation = new Conversation();
                    $conversation->type = $type;
                    $conversation->subject = $request->subject;
                    $conversation->setPreview($body);
                    $conversation->mailbox_id = $request->mailbox_id;
                    $conversation->created_by_user_id = $user->id;
                    $conversation->source_via = Conversation::PERSON_USER;
                    $conversation->source_type = Conversation::SOURCE_TYPE_WEB;
                } else {
                    // Reply or note
                    if ($request_status && $request_status != (int)$conversation->status) {
                        $status_changed = true;
                    }
                    if (!empty($request->subject)) {
                        $conversation->subject = $request->subject;
                    }
                    // When switching from regular message to phone and message sent
                    // without saving a draft type need to be saved here.
                    // Or vise versa.
                    if (($conversation->type == Conversation::TYPE_EMAIL && $type == Conversation::TYPE_PHONE)
                        || ($conversation->type == Conversation::TYPE_PHONE && $type == Conversation::TYPE_EMAIL)
                    ) {
                        $conversation->type = $type;
                    }
                    // Allow to convert phone conversations into email conversations.
                    if ($conversation->isPhone() && !$is_note && $conversation->customer
                        && $customer_email = $conversation->customer->getMainEmail()
                    ) {
                        $conversation->type = Conversation::TYPE_EMAIL;
                        $conversation->customer_email = $customer_email;
                        $is_phone = false;
                    }
                }

                if ($attachments_info['has_attachments']) {
                    $conversation->has_attachments = true;
                }

                // Customer can be empty in existing conversation if this is a draft.
                $customer_email = '';
                $customer = null;

                if ($is_phone && $is_create) {
                    // Phone.
                    $phone_customer_data = $this->processPhoneCustomer($request, $user);

                    if (!empty($phone_customer_data['msg'])) {
                        $response['msg'] = $phone_customer_data['msg'];
                        \DB::rollBack();
                        $this->deleteUncommittedReplyFiles($created_attachments, $saved_files);
                        return $response;
                    }

                    $customer_email = $phone_customer_data['customer_email'];
                    $customer = $phone_customer_data['customer'];
                    if (! $conversation->customer_id) {
                        $conversation->customer_id = $customer->id;
                    }
                } elseif ($is_custom) {
                    // No customer for custom conversations.
                } else {
                    // Email or reply to a phone conversation.
                    if (!empty($to_array)) {
                        $customer_email = $to_array[0];
                    } elseif (!$conversation->customer_email
                        && ($conversation->isEmail() || $conversation->isPhone())
                        && $conversation->customer_id
                        && $conversation->customer
                    ) {
                        // When replying to a phone conversation, we need to
                        // set 'customer_email' for the conversation.
                        $customer_email = $conversation->customer->getMainEmail();
                    }
                    if (!$conversation->customer_id) {
                        $customer = Customer::create($customer_email);
                        $conversation->customer_id = $customer->id;
                    } else {
                        $customer = $conversation->customer;
                    }
                }
                if ($customer_email && !$is_note && !$is_forward) {
                    $conversation->customer_email = $customer_email;
                }

                $prev_status = $conversation->status;

                // A new conversation sent without a status is active (none would be saved as 0).
                $conversation->status = $request_status ?: ($conversation->status ?: Conversation::STATUS_ACTIVE);

                if (($prev_status != $conversation->status || $is_create)
                    && $conversation->isClosed()
                ) {
                    $conversation->closed_by_user_id = $user->id;
                    $conversation->closed_at = date('Y-m-d H:i:s');
                }

                // We need to set state, as it may have been a draft.
                $prev_state = $conversation->state;
                $conversation->state = Conversation::STATE_PUBLISHED;

                // Set assignee
                $prev_user_id = $conversation->user_id;
                if ((int) $request->user_id != -1) {
                    // Check if user has access to the current mailbox
                    if ((int) $conversation->user_id != (int) $request->user_id && $mailbox->userHasAccess($request->user_id)) {
                        $conversation->user_id = $request->user_id;
                        $user_changed = true;
                    }
                } else {
                    $conversation->user_id = null;
                }

                // To is a single email string.
                $to = '';
                // List of emails.
                $to_list = [];
                if ($is_forward) {
                    if (empty($request->to_email[0])) {
                        $response['msg'] = __('Please specify a recipient.');
                        \DB::rollBack();
                        $this->deleteUncommittedReplyFiles($created_attachments, $saved_files);
                        return $response;
                    }
                    $to = $request->to_email[0];
                } else {
                    if (!empty($request->to)) {
                        // When creating a new conversation, to is a list of emails.
                        if (is_array($request->to)) {
                            $to = $request->to[0];
                        } else {
                            $to = $request->to;
                        }
                    } else {
                        $to = $conversation->customer_email;
                    }
                }

                if (!$is_note && !$is_forward) {
                    // Save extra recipients to CC
                    $cc = Conversation::sanitizeEmails($request->cc);
                    if ($is_create && !$is_multiple && count($to_array) > 1) {
                        // First recipient becomes To, others - go to CC.
                        $remaining_to = array_diff($to_array, [$to]);
                        $cc = array_diff($cc, [$to]);
                        $cc = array_merge($cc, $remaining_to);
                        $conversation->setCc($cc);
                    } else {
                        if (!$is_multiple) {
                            $conversation->setCc(array_diff($cc, [$to]));
                        } else {
                            $conversation->setCc($cc);
                        }
                    }
                    $conversation->setBcc($request->bcc);
                    $conversation->last_reply_at = $now;
                    $conversation->last_reply_from = Conversation::PERSON_USER;
                    $conversation->user_updated_at = $now;
                }
                if ($conversation->isPhone() && $is_note) {
                    $conversation->last_reply_at = $now;
                    $conversation->last_reply_from = Conversation::PERSON_USER;
                }
                $conversation->updateFolder();
                if ($from_draft) {
                    // Increment number of replies in conversation
                    $conversation->threads_count++;
                    // We need to set preview here as when conversation is created from draft,
                    // ThreadObserver::created() method is not called.
                    $conversation->setPreview($body);
                }
                $conversation->save();

                // Redirect URL for new not saved yet conversation must be determined here.
                if ($new) {
                    $response['redirect_url'] = $this->getRedirectUrl($request, $conversation, $user);
                }

                // Fire events
                \Eventy::action('conversation.send_reply_save', $conversation, $request);

                $deferred_events[] = function () use ($new, $status_changed, $user_changed, $conversation, $user, $prev_status, $prev_user_id, $prev_state) {
                    if (!$new) {
                        if ($status_changed) {
                            event(new ConversationStatusChanged($conversation));
                            \Eventy::action('conversation.status_changed', $conversation, $user, $changed_on_reply = true, $prev_status);
                        }
                        if ($user_changed) {
                            event(new ConversationUserChanged($conversation, $user));
                            \Eventy::action('conversation.user_changed', $conversation, $user, $prev_user_id);
                        }
                    }
                    if ($conversation->state != $prev_state) {
                        \Eventy::action('conversation.state_changed', $conversation, $user, $prev_state);
                    }
                };

                // Create thread
                if (!$thread) {
                    $thread = new Thread();
                    $thread->conversation_id = $conversation->id;
                    if ($is_note || $is_forward) {
                        $thread->type = Thread::TYPE_NOTE;
                    } else {
                        $thread->type = Thread::TYPE_MESSAGE;
                    }
                    $thread->source_via = Thread::PERSON_USER;
                    $thread->source_type = Thread::SOURCE_TYPE_WEB;
                } else {
                    if ($is_forward || $is_phone) {
                        $thread->type = Thread::TYPE_NOTE;
                    } else {
                        $thread->type = Thread::TYPE_MESSAGE;
                    }
                    $thread->created_at = $now;
                }
                if ($new) {
                    $thread->first = true;
                }
                $thread->user_id = $conversation->user_id;
                $thread->status = $request_status ?? $conversation->status;
                $thread->state = Thread::STATE_PUBLISHED;
                $thread->submission_key = $request->submission_key ?: null;
                if (!$is_custom) {
                    $thread->customer_id = $customer->id;
                }
                $thread->created_by_user_id = $user->id;
                $thread->edited_by_user_id = null;
                $thread->edited_at = null;
                $thread->body = $body;
                $thread->setTo($to);
                // We save CC and BCC as is and filter emails when sending replies
                if ($is_create && !$is_multiple && count($to_array) > 1) {
                    // A new conversation to several recipients: the first one
                    // in To, the others in Cc (like the conversation's Cc).
                    $thread->setCc(array_merge(array_values(array_diff($to_array, [$to])), $request->cc ?: []));
                } else {
                    $thread->setCc($request->cc);
                }
                $thread->setBcc($request->bcc);
                if ($attachments_info['has_attachments'] && !$is_forward) {
                    $thread->has_attachments = true;
                }
                if (!empty($request->saved_reply_id)) {
                    $thread->saved_reply_id = $request->saved_reply_id;
                }

                $forwarded_conversations = [];
                $forwarded_threads = [];

                if ($is_forward) {
                    // Create forwarded conversations.
                    foreach ($to_array as $recipient_email) {
                        $forwarded_conversation = $conversation->replicate();
                        $forwarded_conversation->type = Conversation::TYPE_EMAIL;
                        $forwarded_conversation->setPreview($thread->body);
                        $forwarded_conversation->created_by_user_id = $user->id;
                        $forwarded_conversation->source_via = Conversation::PERSON_USER;
                        $forwarded_conversation->source_type = Conversation::SOURCE_TYPE_WEB;
                        $forwarded_conversation->threads_count = 0; // Counter will be incremented in ThreadObserver.
                        $forwarded_customer = Customer::create($recipient_email);
                        $forwarded_conversation->customer_id = $forwarded_customer->id;
                        // Reload customer object, otherwise it stores previous customer.
                        $forwarded_conversation->load('customer');
                        $forwarded_conversation->customer_email = $recipient_email;
                        $forwarded_conversation->subject = 'Fwd: '.$forwarded_conversation->subject;
                        //$forwarded_conversation->setCc(array_merge(Conversation::sanitizeEmails($request->cc), [$to]));
                        $forwarded_conversation->setCc(Conversation::sanitizeEmails($request->cc));
                        $forwarded_conversation->setBcc($request->bcc);
                        $forwarded_conversation->last_reply_at = $now;
                        $forwarded_conversation->last_reply_from = Conversation::PERSON_USER;
                        $forwarded_conversation->user_updated_at = $now;
                        if ($attachments_info['has_attachments']) {
                            $forwarded_conversation->has_attachments = true;
                        }
                        $forwarded_conversation->updateFolder();
                        $forwarded_conversation->save();
                        $forwarded_thread = $thread->replicate();
                        $forwarded_thread->submission_key = null;
                        $forwarded_thread->setTo($recipient_email);

                        $forwarded_conversations[] = $forwarded_conversation;
                        $forwarded_threads[] = $forwarded_thread;
                    }

                    // Set forwarding meta data.
                    // todo: store array of numbers and IDs.
                    $thread->subtype = Thread::SUBTYPE_FORWARD;
                    $thread->setMeta(Thread::META_FORWARD_CHILD_CONVERSATION_NUMBER, $forwarded_conversations[0]->number);
                    $thread->setMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID, $forwarded_conversations[0]->id);
                }

                // Conversation history.
                if (!empty($request->conv_history)) {
                    if ($request->conv_history != 'global') {
                        if ($is_forward && !empty($forwarded_threads)) {
                            foreach ($forwarded_threads as $forwarded_thread) {
                                $forwarded_thread->setMeta(Thread::META_CONVERSATION_HISTORY, $request->conv_history);
                            }
                        } else {
                            $thread->setMeta(Thread::META_CONVERSATION_HISTORY, $request->conv_history);
                        }
                    }
                }

                // From (mailbox alias).
                if (!empty($request->from_alias)) {
                    $thread->from = $request->from_alias;
                }

                \App\Ai\Drafts::keepTranslation($thread, $request);
                \Eventy::action('thread.before_save_from_request', $thread, $request);
                $thread->save();

                if ($is_forward) {
                    // Save forwarded threads.
                    foreach ($forwarded_conversations as $i => $forwarded_conversation) {
                        $forwarded_thread = $forwarded_threads[$i];

                        $forwarded_thread->conversation_id = $forwarded_conversation->id;
                        $forwarded_thread->type = Thread::TYPE_MESSAGE;
                        $forwarded_thread->subtype = null;
                        if ($attachments_info['has_attachments']) {
                            $forwarded_thread->has_attachments = true;
                        }
                        $forwarded_thread->setMeta(Thread::META_FORWARD_PARENT_CONVERSATION_NUMBER, $conversation->number);
                        $forwarded_thread->setMeta(Thread::META_FORWARD_PARENT_CONVERSATION_ID, $conversation->id);
                        $forwarded_thread->setMeta(Thread::META_FORWARD_PARENT_THREAD_ID, $thread->id);
                        \Eventy::action('send_reply.before_save_forwarded_thread', $forwarded_thread, $request);
                        $forwarded_thread->save();

                        // In the current conversation create Forward-notes corresponding to each recipient.
                        // Forward-note for the first recipient is already created.
                        if ($i != 0) {
                            // $thread contains note created in the original conversation.
                            $forward_note = $thread->replicate();
                            $forward_note->submission_key = null;
                            $forward_note->setTo($forwarded_conversation->customer_email);
                            $forward_note->setMeta(Thread::META_FORWARD_CHILD_CONVERSATION_NUMBER, $forwarded_conversation->number);
                            $forward_note->setMeta(Thread::META_FORWARD_CHILD_CONVERSATION_ID, $forwarded_conversation->id);
                            $forward_note->save();
                        }
                    }
                }

                // If thread has been created from draft, remove the draft
                // if ($request->thread_id) {
                //     $draft_thread = Thread::find($request->thread_id);
                //     if ($draft_thread) {
                //         $draft_thread->delete();
                //     }
                // }

                if ($from_draft) {
                    // Remove conversation from drafts folder if needed
                    $conversation->maybeRemoveFromDrafts();
                }

                // Update folders counters
                $conversation->mailbox->updateFoldersCounters();

                $response['status'] = 'success';

                // Set thread_id for uploaded attachments
                if ($attachments_info['attachments']) {
                    if ($is_forward) {
                        // Copy attachments for each thread.
                        if (count($forwarded_threads) > 1) {
                            $attachments = Attachment::whereIn('id', $attachments_info['attachments'])->get();
                        }
                        foreach ($forwarded_threads as $i => $forwarded_thread) {
                            if ($i == 0) {
                                Attachment::whereIn('id', $attachments_info['attachments'])->update(['thread_id' => $forwarded_thread->id]);
                            } else {
                                foreach ($attachments as $attachment) {
                                    $copy = $attachment->duplicate($forwarded_thread->id, true);
                                    $saved_files[] = $copy->getStorageFilePath();
                                }
                            }
                        }
                    } else {
                        Attachment::whereIn('id', $attachments_info['attachments'])
                            ->where('thread_id', null)
                            ->update(['thread_id' => $thread->id]);
                    }
                }

                // Follow conversation if it's assigned to someone else.
                if (!$is_create && !$new && !$is_forward && !$is_note
                    && $conversation->user_id != $user->id
                ) {
                    $user->followConversation($conversation->id);
                }

                // Nostr replies are sent right away.
                $sent_right_away = !$is_note && \App\Nostr\Nostr::isNostr($conversation);
                if ($sent_right_away) {
                    $can_undo = false;
                }

                $deferred_events[] = function () use ($is_create, $is_forward, $is_note, $conversation, $thread, $forwarded_conversations, $forwarded_threads, $can_undo) {
                    // When user creates a new conversation it may be saved as draft first.
                    if ($is_create) {
                        // New conversation.
                        event(new UserCreatedConversation($conversation, $thread));
                        \Eventy::action('conversation.created_by_user_can_undo', $conversation, $thread);
                        // After Conversation::UNDO_TIMOUT period trigger final event.
                        \Helper::backgroundAction('conversation.created_by_user', [$conversation, $thread], now()->addSeconds($this->getUndoTimeout($can_undo)));
                    } elseif ($is_forward) {
                        // Forward.
                        // Notifications to users not sent.
                        event(new UserAddedNote($conversation, $thread));
                        foreach ($forwarded_conversations as $i => $forwarded_conversation) {
                            $forwarded_thread = $forwarded_threads[$i];

                            // To send email with forwarded conversation.
                            event(new UserReplied($forwarded_conversation, $forwarded_thread));
                            \Eventy::action('conversation.user_forwarded_can_undo', $conversation, $thread, $forwarded_conversation, $forwarded_thread);
                            // After Conversation::UNDO_TIMOUT period trigger final event.
                            \Helper::backgroundAction('conversation.user_forwarded', [$conversation, $thread, $forwarded_conversation, $forwarded_thread], now()->addSeconds(Conversation::UNDO_TIMOUT));
                        }
                    } elseif ($is_note) {
                        // Note.
                        event(new UserAddedNote($conversation, $thread));
                        \Eventy::action('conversation.note_added', $conversation, $thread);
                    } else {
                        // Reply.
                        event(new UserReplied($conversation, $thread));
                        \Eventy::action('conversation.user_replied_can_undo', $conversation, $thread);
                        // After Conversation::UNDO_TIMOUT period trigger final event.
                        \Helper::backgroundAction('conversation.user_replied', [$conversation, $thread], now()->addSeconds($this->getUndoTimeout($can_undo)));
                    }
                };

                // Send new conversation separately to each customer.
                if ($is_create && count($to_array) > 1 && $is_multiple) {
                    $prev_customers_ids = [];
                    foreach ($to_array as $i => $customer_email) {
                        // Skip first email, as conversation has already been created for it.
                        if ($i == 0) {
                            continue;
                        }
                        // Get customer by email.
                        $customer_tmp = Customer::getByEmail($customer_email);
                        // Skip same customers.
                        if ($customer_tmp && in_array($customer_tmp->id, $prev_customers_ids)) {
                            continue;
                        }

                        if (!$customer_tmp) {
                            $customer_tmp = Customer::create($customer_email);
                        }

                        $prev_customers_ids[]  = $customer_tmp->id;

                        // Copy conversation and thread.
                        $conversation_copy = $conversation->replicate();
                        $thread_copy = $thread->replicate();
                        $thread_copy->submission_key = null;

                        // Save conversation.
                        $conversation_copy->threads_count = 0;
                        $conversation_copy->customer_id = $customer_tmp->id;
                        // Reload customer, otherwise all recipients will have the same name.
                        $conversation_copy->load('customer');
                        $conversation_copy->customer_email = $customer_email;
                        $conversation_copy->has_attachments = (bool)$conversation->has_attachments;
                        $conversation_copy->push();

                        $thread_copy->conversation_id = $conversation_copy->id;
                        $thread_copy->customer_id = $customer_tmp->id;
                        $thread_copy->has_attachments = (bool)$conversation->has_attachments;
                        $thread_copy->setTo($customer_email);
                        // Reload the conversation, otherwise Thread observer will be
                        // increasing threads_count for the first conversation.
                        $thread_copy->load('conversation');

                        \Eventy::action('thread.before_save_from_request', $thread_copy, $request);

                        $thread_copy->push();

                        // Copy attachments.
                        if (!empty($attachments_info['attachments'])) {
                            $attachments = Attachment::whereIn('id', $attachments_info['attachments'])->get();
                            foreach ($attachments as $attachment) {
                                $copy = $attachment->duplicate($thread_copy->id, true);
                                $saved_files[] = $copy->getStorageFilePath();
                            }
                        }

                        $deferred_events[] = function () use ($conversation_copy, $thread_copy, $can_undo) {
                            event(new UserCreatedConversation($conversation_copy, $thread_copy));
                            \Eventy::action('conversation.created_by_user_can_undo', $conversation_copy, $thread_copy);
                            \Helper::backgroundAction('conversation.created_by_user', [$conversation_copy, $thread_copy], now()->addSeconds($this->getUndoTimeout($can_undo)));
                        };
                    }
                }

                \DB::commit();
            } catch (\Throwable $e) {
                \DB::rollBack();
                $this->deleteUncommittedReplyFiles($created_attachments, $saved_files);
                $response['status'] = 'error';
                if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException && $request->submission_key) {
                    $submitted = Thread::where('submission_key', $request->submission_key)->first();
                    if ($submitted) {
                        return $this->alreadySubmittedReply($request, $response, $user, $submitted);
                    }
                }
                \Helper::logException($e, '[ConversationsController::ajaxSendReply()]');
                $response['msg'] = __('Error occurred. Please try again later.');

                return $response;
            }

            foreach ($removed_files as $path) {
                try {
                    Attachment::getDisk()->delete($path);
                } catch (\Throwable $e) {
                    \Helper::logException($e, '[ConversationsController::ajaxSendReply()]');
                }
            }
            foreach ($deferred_events as $dispatch) {
                $dispatch();
            }

            // Compose flash message.
            $show_view_link = true;
            if (!empty($request->after_send) && $request->after_send == MailboxUser::AFTER_SEND_STAY) {
                $show_view_link = false;
            }

            if ($is_phone) {
                $flash_type = 'warning';
                $flash_text = __('Conversation created');
            } elseif ($is_custom) {
                $flash_type = 'warning';
                $identifier = \Eventy::filter('conversation.custom.identifier', __('Custom conversation'), $request);
                // The translations keep FreeScout's %identifier%, which __() doesn't fill in.
                $flash_text = str_replace('%identifier%', e($identifier), __('%identifier% added'));
            } elseif ($is_note) {
                $flash_type = 'warning';
                $flash_text = __('Note added');
            } else {
                $flash_type = 'success';
                $flash_text = __('Message sent');
            }

            if ($can_undo) {
                \Session::flash('flash_'.$flash_type.'_floating', $flash_text);
                \Session::flash('flash_undo_floating', [
                    'thread_id' => $thread->id,
                    'text' => $flash_text,
                    'expires_at' => $thread->created_at->copy()->addSeconds(Conversation::UNDO_TIMOUT)->timestamp,
                ]);
            } elseif ($sent_right_away) {
                \Session::flash('flash_success_floating', '<strong>'.__('Message sent').'</strong>'.($show_view_link ? ' &nbsp;<a href="'.$conversation->url().'">'.__('View').'</a>' : ''));
            }
        }

        return $response;
    }

    /**
     * Return a successful retry only for the same user's completed submission.
     */
    private function alreadySubmittedReply($request, $response, $user, $thread)
    {
        $conversation = $thread->conversation;
        if ($request->submission_key && $thread->submission_key == $request->submission_key
            && $thread->created_by_user_id == $user->id
            && $conversation && $conversation->mailbox_id == $request->mailbox_id
            && (!$request->conversation_id || $conversation->id == $request->conversation_id)
            && $user->can('view', $conversation)
        ) {
            $response['status'] = 'success';
            $response['redirect_url'] = $this->getRedirectUrl($request, $conversation, $user);
        } else {
            $response['msg'] = __('Message has been already sent. Please discard this draft.');
        }

        return $response;
    }

    private function deleteUncommittedReplyFiles($created_attachments, $saved_files)
    {
        foreach ($created_attachments as $attachment) {
            $saved_files[] = $attachment->getStorageFilePath();
        }
        foreach ($saved_files as $path) {
            try {
                Attachment::getDisk()->delete($path);
            } catch (\Throwable $e) {
                \Helper::logException($e, '[ConversationsController::deleteUncommittedReplyFiles()]');
            }
        }
    }

    private function saveDraftRequest(Request $request, $response, $user)
    {
        $mailbox = Mailbox::findOrFail($request->mailbox_id);

        if (!$response['msg'] && !$user->can('view', $mailbox)) {
            $response['msg'] = __('Not enough permissions');
        }

        $conversation = null;
        // Conversation does not exist yet.
        $new = true;
        if (!$response['msg'] && !empty($request->conversation_id)) {
            $conversation = Conversation::find($request->conversation_id);
            if ($conversation && !$user->can('view', $conversation) /*&& !$user->hasManageMailboxPermission($request->mailbox_id, Mailbox::ACCESS_PERM_ASSIGNED)*/) {
                $response['msg'] = __('Not enough permissions');
            } else {
                $new = false;
            }
        }

        // Draft saved from "New Conversation" page.
        $is_create = false;
        if (!empty($request->is_create)) {
            $is_create = true;
        }

        // If new conversation draft has been discarded (by some other user for example).
        // https://github.com/freescout-helpdesk/freescout/issues/3951
        if (!$response['msg'] && $is_create && !$conversation) {
            $new = true;
        }

        $thread = null;
        $new_thread = true;
        if (!$response['msg'] && !empty($request->thread_id)) {
            $thread = Thread::find($request->thread_id);
            if ($thread && (!$conversation || $thread->conversation_id != $conversation->id)) {
                $response['msg'] = __('Incorrect thread');
            } else {
                $new_thread = false;
            }
        }

        // Check if thread has been sent (in other window for example).
        if (!$response['msg']) {
            if ($thread && $thread->state == Thread::STATE_PUBLISHED) {
                $response['msg'] = __('Message has been already sent. Please discard this draft.');
            }
        }

        // To prevent creating draft after reply has been created.
        if (!$response['msg'] && $conversation) {
            // Check if the last thread has same content as the new one.
            $last_thread = $conversation->getLastThread([Thread::TYPE_MESSAGE, Thread::TYPE_NOTE]);

            if ($last_thread
                && $last_thread->created_by_user_id == $user->id
                && $last_thread->body == $request->body
            ) {
                //\Log::error("You've already sent this message just recently.");
                $response['msg'] = __("You've already sent this message just recently.");
            }
        }

        // Make sure that conversation is in Draft state.
        // https://github.com/freescout-help-desk/freescout/security/advisories/GHSA-6ff4-3w2c-mjj8
        if (!$response['msg']
            && $conversation
            && !$conversation->isDraft()
            && ($new || $is_create)
        ) {
            $response['msg'] = __('Message has been already sent. Please discard this draft.');
        }

        // Validation is not needed on draft create, fields can be empty

        // Update conversation data.
        if (!$response['msg']) {

            // Get attachments info
            $attachments_info = $this->processReplyAttachments($request, $thread->id ?? null);

            // Conversation
            $now = date('Y-m-d H:i:s');

            if ($new) {
                $conversation = new Conversation();
            }

            // To is a single email or array of emails.
            $to = '';

            if ($new || $is_create) {
                // New conversation
                $customer_email = '';
                $customer = null;

                $type = Conversation::TYPE_EMAIL;
                if (!empty($request->type)) {
                    $type = (int)$request->type;
                }

                if ($type == Conversation::TYPE_PHONE) {
                    // Phone.
                    $phone_customer_data = $this->processPhoneCustomer($request, $user);

                    if (!empty($phone_customer_data['msg'])) {
                        $response['msg'] = $phone_customer_data['msg'];
                        return $response;
                    }

                    $customer_email = $phone_customer_data['customer_email'];
                    $customer = $phone_customer_data['customer'];
                } else {
                    // Email.
                    // Now instead of customer_email we store emails in thread->to.
                    $to_array = Conversation::sanitizeEmails($request->to);
                    if (count($to_array)) {
                        if (count($to_array) == 1) {
                            //$customer_email = array_first($to_array);
                            $to = array_first($to_array);
                            $customer = Customer::create($customer_email);
                        } else {
                            // Creating a conversation to multiple customers
                            // In customer_email temporary store a list of customer emails.
                            //$customer_email = implode(',', $to_array);
                            $to = $to_array;
                            
                            // Keep $customer as null.
                            // When conversation will be sent, separate conversation
                            // will be created for each customer.
                            $customer = null;
                        }
                    }
                }

                $conversation->type = $type;
                $conversation->state = Conversation::STATE_DRAFT;
                $conversation->status = (int) $request->status ?: Conversation::STATUS_ACTIVE;
                $conversation->subject = $request->subject;
                $conversation->setPreview($request->body);
                if ($attachments_info['has_attachments']) {
                    $conversation->has_attachments = true;
                }
                $conversation->mailbox_id = $request->mailbox_id;
                // Customer may be empty in draft
                if ($customer) {
                    $conversation->customer_id = $customer->id;
                }
                $conversation->customer_email = $customer_email;
                $conversation->created_by_user_id = $user->id;
                $conversation->source_via = Conversation::PERSON_USER;
                $conversation->source_type = Conversation::SOURCE_TYPE_WEB;
            } else {
                // Reply
                $customer = $conversation->customer;
            }

            // New draft conversation is not assigned to anybody
            //$conversation->user_id = null;

            if (empty($request->to) || !is_array($request->to)) {
                if (!empty($request->to)) {
                    // New conversation.
                    $to = $request->to;
                } elseif (!empty($request->to_email)) {
                    // Forwarding.
                    $to = $request->to_email;
                } else {
                    $to = $conversation->customer_email;
                }
            }

            // Conversation type.
            if (!empty($request->type) && array_key_exists((int)$request->type, Conversation::$types)) {
                $conversation->type = (int)$request->type;
            }

            // Save extra recipients to CC
            if ($is_create) {
                //$conversation->setCc(array_merge(Conversation::sanitizeEmails($request->cc), (is_array($to) ? $to : [$to])));
                $conversation->setCc($request->cc);
                $conversation->setBcc($request->bcc);
            }
            // $conversation->last_reply_at = $now;
            // $conversation->last_reply_from = Conversation::PERSON_USER;
            // $conversation->user_updated_at = $now;
            $conversation->updateFolder();

            $conversation->save();

            // Create thread
            if (empty($thread)) {
                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id = $user->id;
                //$thread->type = Thread::TYPE_MESSAGE;
                if ($new) {
                    $thread->first = true;
                }
                //$thread->status = $request->status;
                $thread->state = Thread::STATE_DRAFT;

                $thread->source_via = Thread::PERSON_USER;
                $thread->source_type = Thread::SOURCE_TYPE_WEB;
                if ($customer) {
                    $thread->customer_id = $customer->id;
                }
                $thread->created_by_user_id = $user->id;
                // User is forwarding a conversation.
                if (!empty($request->subtype) && (int)$request->subtype) {
                    $thread->subtype = $request->subtype;
                }
            }
            if ($attachments_info['has_attachments']) {
                $thread->has_attachments = true;
            }
            // Thread type.
            if ($is_create && !empty($request->is_note)) {
                $thread->type = Thread::TYPE_NOTE;
            } else {
                $thread->type = Thread::TYPE_MESSAGE;
            }
            $thread->from = $request->from_alias ?? null;
            $thread->body = $request->body;
            $thread->setTo($to);
            // We save CC and BCC as is and filter emails when sending replies
            $thread->setCc($request->cc);
            $thread->setBcc($request->bcc);
            // Set edited info
            if ($thread->created_by_user_id != $user->id) {
                $thread->edited_by_user_id = $user->id;
                $thread->edited_at = $now;
            }
            $thread->save();

            $conversation->addToFolder(Folder::TYPE_DRAFTS);

            $response['conversation_id'] = $conversation->id;
            $response['customer_id'] = $conversation->customer_id;
            $response['thread_id'] = $thread->id;
            $response['number'] = $conversation->number;

            $response['status'] = 'success';

            // Set thread_id for uploaded attachments
            if ($attachments_info['attachments']) {
                Attachment::whereIn('id', $attachments_info['attachments'])
                    ->where('thread_id', null)
                    ->update(['thread_id' => $thread->id]);
            }

            // Update folder counter.
            $conversation->mailbox->updateFoldersCounters(Folder::TYPE_DRAFTS);

            if ($new) {
                event(new UserCreatedConversationDraft($conversation, $thread));
            } elseif ($new_thread) {
                event(new UserCreatedThreadDraft($conversation, $thread));
            }

            $response['status'] = 'success';
        }

        // Reflash session data - otherwise on reply flash alert is not displayed
        // https://stackoverflow.com/questions/37019294/laravel-ajax-call-deletes-session-flash-data
        \Session::reflash();


        return $response;
    }

    private function discardDraftRequest(Request $request, $response, $user)
    {
        $thread = Thread::find($request->thread_id);

        if (!$thread) {
            // Discarding nont saved yet draft
            $response['status'] = 'success';

            // Discarding a new conversation being created from thread
            if (!empty($request->from_thread_id)) {
                $original_thread = Thread::find($request->from_thread_id);
                if ($original_thread && $original_thread->conversation_id) {
                    // Open original conversation
                    $response['redirect_url'] = route('conversations.view', ['id' => $original_thread->conversation_id]);
                }
            }
            return $response;
            //$response['msg'] = __('Thread not found');
        }
        if (!$response['msg'] && !$user->can('view', $thread->conversation)) {
            $response['msg'] = __('Not enough permissions');
        }

        if (!$response['msg']) {
            $conversation = $thread->conversation;

            if ($conversation->state == Conversation::STATE_DRAFT) {
                // New conversation draft being discarded
                $folder_id = $conversation->getCurrentFolder(null, $request);
                $response['redirect_url'] = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $folder_id]);

                $mailbox = $conversation->mailbox;

                $conversation->removeFromFolder(Folder::TYPE_DRAFTS);
                $conversation->removeFromFolder(Folder::TYPE_STARRED, $user->id);
                $mailbox->updateFoldersCounters(Folder::TYPE_DRAFTS);
                $conversation->deleteThreads();
                $conversation->delete();

                // Draft may be present in Starred folder.
                Conversation::clearStarredByUserCache($user->id, $mailbox->id);
                $mailbox->updateFoldersCounters(Folder::TYPE_STARRED);

                $flash_message = __('Deleted draft');
                \Session::flash('flash_success_floating', $flash_message);
            } else {
                // https://github.com/freescout-helpdesk/freescout/issues/2873
                if ($thread->state == Thread::STATE_DRAFT) {
                    // Just remove the thread, no need to reload the page
                    $thread->deleteThread();
                    // Remove conversation from drafts folder if needed
                    $removed_from_folder = $conversation->maybeRemoveFromDrafts();
                    if ($removed_from_folder) {
                        $conversation->mailbox->updateFoldersCounters(Folder::TYPE_DRAFTS);
                    }
                }
            }

            $response['status'] = 'success';
        }

        return $response;
    }

    public function processReplyAttachments($request, $thread_id = null, $delete_removed = true)
    {
        $has_attachments = false;
        $attachments = [];
        if (!empty($request->attachments_all)) {
            $embeds = [];
            $attachments_all = $this->decodeAttachmentsIds($request->attachments_all);
            if (!empty($request->attachments)) {
                $attachments = $this->decodeAttachmentsIds($request->attachments);
            }
            if (!empty($request->embeds)) {
                $embeds = $this->decodeAttachmentsIds($request->embeds);
            }
            $attachments_to_remove = array_diff($attachments_all, $attachments);
            $attachments_to_remove = array_diff($attachments_to_remove, $embeds);
            if (count($attachments) 
                && count($attachments) != count($embeds)
            ) {
                $has_attachments = true;
            }
            // Sanitize $attachments_to_remove list.
            if (count($attachments_to_remove)) {
                $attachments_check = Attachment::select('id', 'thread_id')
                    ->whereIn('id', $attachments_to_remove)
                    ->get();
                foreach ($attachments_check as $attachment) {
                    if ($attachment->thread_id && $attachment->thread_id != $thread_id) {
                        $attachments_to_remove = array_diff($attachments_to_remove, [$attachment->id]);
                    }
                }
                if ($delete_removed) {
                    Attachment::deleteByIds($attachments_to_remove);
                }
            }
        }

        return [
            'has_attachments' => $has_attachments,
            'attachments'     => $attachments,
            'attachments_to_remove' => $attachments_to_remove ?? [],
        ];
    }

    public function decodeAttachmentsIds($attachments_list)
    {
        foreach ($attachments_list as $i => $attachment_id) {
            $attachment_id_decrypted = \Helper::decrypt($attachment_id);
            // Not encrypted by us: decrypt() gives '' (PostgreSQL refuses it as an ID).
            if ($attachment_id_decrypted == $attachment_id || !is_numeric($attachment_id_decrypted)) {
                unset($attachments_list[$i]);
            } else {
                $attachments_list[$i] = $attachment_id_decrypted;
            }
        }

        return $attachments_list;
    }

    public function processPhoneCustomer($request, $user)
    {
        $customer_data = [];
        $customer_email = '';
        $customer = null;

        // Check to prevent creating empty customers.
        $request_name = '';
        $request_phone = '';
        if (trim($request->name ?? '') || trim($request->phone ?? '')) {
            $request_name = trim($request->name ?? '');
            $request_phone = trim($request->phone ?? '');

            $name_parts = explode(' ', $request_name);
            $customer_data['first_name'] = $name_parts[0];
            if (!empty($name_parts[1])) {
                $customer_data['last_name'] = $name_parts[1];
            }
            if ($request_phone) {
                $customer_data['phones'] = [$request_phone];
            }
        }

        // Check if name field contains ID of the customer.
        if (!$request->customer_id && is_numeric($request_name)) {
            // Try to find customer by ID.
            $customer = Customer::find($request_name);
            if ($customer) {
                if (!$user->can('view', $customer)) {
                    return [
                        'status' => 'error',
                        'msg' => __('Inaccessible customer'),
                    ];
                }
            }
        }

        if (!$customer && $request->to_email) {
            // Try to get customer by email.
            $customer = Customer::getByEmail($request->to_email);
            if ($customer) {
                $customer_email = $request->to_email;
            }
        }

        // Try to find customer by phone.
        if (!$customer && $request_phone) {
            $customer = Customer::findByPhone($request_phone);
            if ($customer) {
                $customer_email = $customer->getMainEmail();
            }
        }

        if (!$customer) {
            // Create customer with passed name, email and phone
            if (Email::sanitizeEmail($request->to_email)) {
                $customer_email = $request->to_email;
                // If new email entered, attach email to the current customer
                // instead of creating a new customer
                if ($request->customer_id) {
                    $customer = Customer::find($request->customer_id);
                    if ($customer) {
                        if (!$user->can('view', $customer)) {
                            return [
                                'status' => 'error',
                                'msg' => __('Inaccessible customer'),
                            ];
                        }
                        // Add email to customer.
                        $customer->addEmail($customer_email, true);
                    } else {
                        $customer = Customer::create($customer_email, $customer_data);
                    }
                } else {
                    $customer = Customer::create($customer_email, $customer_data);
                }
            } elseif ($customer_data) {
                if ($request->customer_id) {
                    $customer = Customer::find($request->customer_id);
                    if ($customer) {
                        if (!$user->can('view', $customer)) {
                            return [
                                'status' => 'error',
                                'msg' => __('Inaccessible customer'),
                            ];
                        }
                        $customer->setData($customer_data, false, true);
                    }
                }

                if (!$customer) {
                    $customer = Customer::createWithoutEmail($customer_data);
                }
            }
        } else {
            $customer->setData($customer_data, false, true);
            // Add email to customer.
            if (Email::sanitizeEmail($request->to_email)) {
                $customer->addEmail($request->to_email, true);
            }
        }

        return [
            'customer' => $customer,
            'customer_email' => $customer_email,
        ];
    }

    public function getUndoTimeout($can_undo)
    {
        if ($can_undo) {
            return Conversation::UNDO_TIMOUT;
        } else {
            return 1;
        }
    }
}

<?php

namespace App\Misc;

use App\Conversation;
use App\Follower;
use App\MailboxUser;
use App\Thread;

/**
 * What a user does to a conversation from its page: assign, change the status,
 * restore, follow, delete and change the subject; edit a message, delete a
 * note and send a failed reply again. Used by the conversation ajax actions
 * and the page's Livewire components.
 *
 * Each returns the ajax response's fields: msg (an error), or status success
 * with redirect_url or msg_success where the action has them.
 */
class ConversationActions
{
    public static function changeUser($conversation, $new_user_id, $user, $request)
    {
        $new_user_id = (int) $new_user_id;

        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }
        if ($conversation->user_id == $new_user_id) {
            return ['msg' => __('Assignee already set')];
        }
        if (!$user->can('update', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }
        if ($new_user_id != -1 && !$conversation->mailbox->userHasAccess($new_user_id)) {
            return ['msg' => __('Not enough permissions')];
        }

        // Determine redirect
        // Must be done before updating current conversation's status or assignee.
        $redirect_same_page = false;
        if ($new_user_id == $user->id || $request->x_embed == 1) {
            // If user assigned conversation to himself, stay on the current page
            $redirect_url = $conversation->url();
            $redirect_same_page = true;
        } else {
            $redirect_url = self::redirectUrl($request, $conversation, $user);
        }

        $conversation->changeUser($new_user_id, $user);

        // Flash
        $flash_message = __('Assignee updated');
        if (!$redirect_same_page || $redirect_url != $conversation->url()) {
            $flash_message .= ' &nbsp;<a href="'.$conversation->url().'">'.__('View').'</a>';
        }
        \Session::flash('flash_success_floating', $flash_message);

        return ['status' => 'success', 'redirect_url' => $redirect_url, 'msg' => __('Assignee updated')];
    }

    /**
     * A status, or not_spam: back to the status before Spam.
     */
    public static function changeStatus($conversation, $status, $user, $request)
    {
        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }

        if ($status == 'not_spam') {
            // Find previous status in threads
            $new_status = $conversation
                ->threads()
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                ->where('status', '!=', Thread::STATUS_SPAM)
                ->where('type', Thread::TYPE_LINEITEM)
                ->where('action_type', Thread::ACTION_TYPE_STATUS_CHANGED)
                ->value('status');
            if (!$new_status) {
                $new_status = Thread::STATUS_ACTIVE;
            }
        } else {
            $new_status = (int) $status;
        }

        if ($conversation->status == $new_status) {
            return ['msg' => __('Status already set')];
        }
        if (!$user->can('update', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }
        if (!in_array((int) $new_status, array_keys(Conversation::$statuses))) {
            return ['msg' => __('Incorrect status')];
        }

        // Determine redirect
        // Must be done before updating current conversation's status or assignee.
        $redirect_same_page = false;
        if ($status == 'not_spam' || $request->x_embed == 1) {
            // Stay on the current page
            $redirect_url = $conversation->url();
            $redirect_same_page = true;
        } else {
            $redirect_url = self::redirectUrl($request, $conversation, $user);
        }

        $conversation->changeStatus($new_status, $user);

        // Flash
        $flash_message = __('Status updated');
        if (!$redirect_same_page || $redirect_url != $conversation->url()) {
            $flash_message .= ' &nbsp;<a href="'.$conversation->url().'">'.__('View').'</a>';
        }
        \Session::flash('flash_success_floating', $flash_message);

        return ['status' => 'success', 'redirect_url' => $redirect_url, 'msg' => __('Status updated')];
    }

    public static function restore($conversation, $user)
    {
        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }
        if (!$user->can('delete', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }
        if ($conversation->state != Conversation::STATE_DELETED) {
            return ['msg' => __('Only deleted conversations can be restored.')];
        }

        $prev_state = $conversation->state;
        $conversation->state = Conversation::STATE_PUBLISHED;
        // Expired by retention: its clock starts over from now.
        if ($conversation->expired_at) {
            $conversation->expired_at = null;
            $conversation->retention_reset_at = now();
        }
        $conversation->user_updated_at = date('Y-m-d H:i:s');
        $conversation->updateFolder();
        $conversation->save();

        // Create lineitem thread
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $conversation->user_id;
        $thread->type = Thread::TYPE_LINEITEM;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = Thread::STATUS_NOCHANGE;
        $thread->action_type = Thread::ACTION_TYPE_RESTORE_TICKET;
        $thread->source_via = Thread::PERSON_USER;
        // todo: this need to be changed for API
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->save();

        // Recalculate only old and new folders
        $conversation->mailbox->updateFoldersCounters();

        if ($prev_state != $conversation->state) {
            \Eventy::action('conversation.state_changed', $conversation, $user, $prev_state);
        }

        \Session::flash('flash_success_floating', __('Conversation restored'));

        return ['status' => 'success'];
    }

    public static function follow($conversation, $user, $follow = true)
    {
        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }
        if (!$user->can('view', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }

        if ($follow) {
            $user->followConversation($conversation->id);
        } else {
            $follower = Follower::where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->first();
            if ($follower) {
                $follower->delete();
            }
        }

        return ['status' => 'success', 'msg_success' => $follow ? __('Following') : __('Unfollowed')];
    }

    /**
     * Moves the conversation to the Deleted folder, or deletes it forever
     * when it is there.
     */
    public static function delete($conversation, $user, $forever = false)
    {
        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }
        if (!$user->can('delete', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }
        if ($forever && $conversation->state != Conversation::STATE_DELETED) {
            // Like the UI, which offers "Delete Forever" in the Deleted folder only.
            return ['msg' => __('Only deleted conversations can be deleted forever.')];
        }

        $folder_id = $conversation->getCurrentFolder();

        if ($forever) {
            $mailbox = $conversation->mailbox;
            $conversation->deleteForever();
            // Recalculate only old and new folders
            $mailbox->updateFoldersCounters();
        } else {
            $conversation->deleteToFolder($user);
        }

        \Session::flash('flash_success_floating', __('Conversation deleted'));

        return ['status' => 'success', 'redirect_url' => route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $folder_id])];
    }

    public static function changeSubject($conversation, $subject, $user)
    {
        if (!$conversation) {
            return ['msg' => __('Conversation not found')];
        }
        if (!$user->can('update', $conversation)) {
            return ['msg' => __('Not enough permissions')];
        }

        $subject = trim($subject ?? '');
        if (!$subject) {
            return [];
        }
        $conversation->changeSubject($subject, $user);

        return ['status' => 'success'];
    }

    /**
     * Changes a message's body, keeping the original.
     */
    public static function editThread($thread, $body, $user)
    {
        if (!$thread) {
            return ['msg' => __('Thread not found')];
        }
        if (!$user->can('edit', $thread)) {
            return ['msg' => __('Not enough permissions')];
        }

        if (!$thread->body_original) {
            $thread->body_original = $thread->body;
        }
        $thread->body = $body;
        $thread->edited_by_user_id = $user->id;
        $thread->edited_at = date('Y-m-d H:i:s');
        $clean_body = $thread->getCleanBody();

        if (!strip_tags($clean_body)) {
            return ['msg' => __('Message cannot be empty')];
        }

        // Update the preview for the conversation if needed.
        $last_thread = $thread->conversation->getLastThread([Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE]);
        if ($last_thread && $last_thread->id == $thread->id) {
            $thread->conversation->setPreview($thread->body);
            $thread->conversation->save();
        }
        $thread->save();

        return ['status' => 'success', 'body' => $clean_body];
    }

    public static function deleteNote($thread, $user)
    {
        if (!$thread || !$thread->isNote()) {
            return ['msg' => __('Thread not found')];
        }
        if (!$user->can('delete', $thread)) {
            return ['msg' => __('Not enough permissions')];
        }

        $thread->deleteThread();

        return ['status' => 'success'];
    }

    /**
     * Sends a reply that failed to send again.
     */
    public static function retrySend($thread, $user)
    {
        if (!$thread) {
            return ['msg' => __('Thread not found')];
        }
        if (!$user->can('view', $thread->conversation)) {
            return ['msg' => __('Not enough permissions')];
        }

        $job_id = $thread->getFailedJobId();
        if (!$job_id && !$thread->canRetrySend()) {
            return [];
        }

        // Not sent yet: the job skips replies already accepted.
        $thread->send_status = null;
        $thread->updateSendStatusData(['msg' => '']);
        $thread->save();

        if ($job_id) {
            \App\FailedJob::retry($job_id);
        } else {
            // Never queued, or its failed job has been cleaned up.
            (new \App\Listeners\SendReplyToCustomer())->handle(new \App\Events\UserReplied($thread->conversation, $thread));
        }

        return ['status' => 'success'];
    }

    /**
     * Where the user goes after replying or changing a conversation: the
     * conversation or the next active conversation (the user's
     * preference, or after_send in the request).
     */
    public static function redirectUrl($request, $conversation, $user)
    {
        if (!empty($request->after_send)) {
            $after_send = $request->after_send;
        } else {
            // The user's preference (users/preferences).
            $after_send = $user->afterSend();
        }

        // When creating a new conversation.
        if (!empty($request->is_create) && $after_send != MailboxUser::AFTER_SEND_STAY) {
            return route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $conversation->folder_id]);
        }

        if (!empty($after_send)) {
            switch ($after_send) {
                case MailboxUser::AFTER_SEND_STAY:
                default:
                    $redirect_url = $conversation->url(null, null, [], $request);
                    break;
                case MailboxUser::AFTER_SEND_NEXT:
                    // We need to get not any next conversation, but ACTIVE next conversation.
                    $redirect_url = $conversation->urlNext(Conversation::getFolderParam($request), Conversation::STATUS_ACTIVE, true, $request);
                    break;
            }
        } else {
            // If something went wrong and after_send not set, just show the reply
            $redirect_url = $conversation->url(null, null, [], $request);
        }

        return $redirect_url;
    }
}

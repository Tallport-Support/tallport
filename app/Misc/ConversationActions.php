<?php

namespace App\Misc;

use App\Conversation;
use App\Follower;
use App\Thread;

/**
 * What a user does to a conversation from its page: assign, change the status,
 * restore, follow, delete and change the subject; edit a message, delete a
 * note and send a failed reply again. Used by the page's Livewire components.
 *
 * Each returns msg (an error), or status success with redirect_url or
 * msg_success where the action has them.
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

        // Taking it means working on it: the user stays on it, as in an embedded page.
        $redirect = $new_user_id == $user->id || $request->x_embed == 1
            ? fn () => $conversation->url()
            : self::redirect($conversation, $user);

        $conversation->changeUser($new_user_id, $user);
        $redirect_url = $redirect();

        // Flash
        $flash_message = __('Assignee updated');
        if ($redirect_url != $conversation->url()) {
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
        if ($conversation->isChatUnavailable() && in_array(Conversation::toMainStatus($new_status), [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])) {
            return ['msg' => ChatConversations::unavailableMessage()];
        }

        $redirect = $request->x_embed == 1 ? fn () => $conversation->url() : self::redirect($conversation, $user);

        $conversation->changeStatus($new_status, $user);
        $redirect_url = $redirect();

        // Flash
        $flash_message = __('Status updated');
        if ($redirect_url != $conversation->url()) {
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

        $folder = $conversation->folder;

        if ($forever) {
            $mailbox = $conversation->mailbox;
            $conversation->deleteForever();
            // Recalculate only old and new folders
            $mailbox->updateFoldersCounters();
        } else {
            $conversation->deleteToFolder($user);
        }

        \Session::flash('flash_success_floating', __('Conversation deleted'));

        // Back to the selected folder.
        return ['status' => 'success', 'redirect_url' => \App\Misc\Sidebar::folderUrl($folder, $conversation->mailbox_id)];
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
        // Edited as it was written: in Markdown (the body rendered from it), or in rich text.
        if ($thread->body_markdown !== null) {
            $thread->body_markdown = (string) $body;
            $body = \App\Misc\Markdown::toHtml($body, $thread->type == Thread::TYPE_NOTE ? null : $thread->conversation->editorFormats());
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

        if ($thread->conversation->isChatUnavailable()) {
            return ['msg' => ChatConversations::unavailableMessage()];
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
     * Where the user goes after replying to or changing a conversation. It's decided before
     * the change, while the conversation is still in its folder: the closure returned gives
     * the URL once the change is made. When the conversation left the folder the user is in,
     * they stay in the conversation or in the folder, at its next active conversation (their
     * preference, users.stay_in_conversation); else they stay on it. A new conversation: it,
     * or its folder.
     */
    public static function redirect($conversation, $user, $is_create = false)
    {
        $stay_in_conversation = (bool) $user->stay_in_conversation;

        if ($is_create) {
            return fn () => $stay_in_conversation ? $conversation->url() : \App\Misc\Sidebar::folderUrl($conversation->folder, $conversation->mailbox_id);
        }

        $folder = \App\Misc\Sidebar::conversationFolder($conversation, $user) ?: $conversation->folder;
        $next_url = null;
        if (!$stay_in_conversation) {
            $next = $conversation->getNearby('next', $folder->id, Conversation::STATUS_ACTIVE, true);
            $next_url = $next ? $next->url() : \App\Misc\Sidebar::folderUrl($folder, $conversation->mailbox_id);
        }

        return function () use ($conversation, $user, $folder, $next_url) {
            $moved = \App\Misc\Sidebar::conversationFolder($conversation->fresh() ?: $conversation, $user)?->id != $folder->id;
            if ($next_url && $moved) {
                return $next_url;
            }

            return $conversation->url();
        };
    }
}

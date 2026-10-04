<?php

namespace App\Misc;

use App\Conversation;
use App\Follower;
use App\MailboxUser;
use App\Thread;

/**
 * What a user does to a conversation from its page: assign, change the status,
 * restore, follow, delete and change the subject. Used by the conversation
 * ajax actions and the Livewire toolbar and heading.
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
                ->orderBy('created_at', 'desc')
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
     * Where the user goes after replying or changing a conversation: the
     * conversation, its folder or the next active conversation (the user's
     * mailbox setting, or after_send in the request).
     */
    public static function redirectUrl($request, $conversation, $user)
    {
        if (!empty($request->after_send)) {
            $after_send = $request->after_send;
        } else {
            // todo: use $user->mailboxSettings()
            $after_send = $conversation->mailbox->getUserSettings($user->id)->after_send;
        }

        // When creating a new conversation.
        if (!empty($request->is_create) && $after_send != MailboxUser::AFTER_SEND_STAY) {
            return route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $conversation->folder_id]);
        }

        if (!empty($after_send)) {
            switch ($after_send) {
                case MailboxUser::AFTER_SEND_STAY:
                default:
                    $redirect_url = $conversation->url();
                    break;
                case MailboxUser::AFTER_SEND_FOLDER:
                    $folder_id = Conversation::getFolderParam();
                    if (!$folder_id) {
                        $folder_id = $conversation->folder_id;
                    }
                    $redirect_url = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $folder_id]);
                    break;
                case MailboxUser::AFTER_SEND_NEXT:
                    // We need to get not any next conversation, but ACTIVE next conversation.
                    $redirect_url = $conversation->urlNext(Conversation::getFolderParam(), Conversation::STATUS_ACTIVE, true);
                    break;
            }
        } else {
            // If something went wrong and after_send not set, just show the reply
            $redirect_url = $conversation->url();
        }

        return $redirect_url;
    }
}

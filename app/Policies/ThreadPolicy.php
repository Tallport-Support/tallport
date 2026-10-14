<?php

namespace App\Policies;

use App\Thread;
use App\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ThreadPolicy
{
    use HandlesAuthorization;

    /**
     * Whether the user can edit the thread: only their own notes. What was sent (replies,
     * forwards), what customers wrote and what others wrote are as they are; drafts are
     * edited in the composer. Admins, or users with the permission, in conversations they see.
     */
    public function edit(User $user, Thread $thread)
    {
        return $thread->type == Thread::TYPE_NOTE
            && $thread->created_by_user_id == $user->id
            && $thread->conversation
            && ($user->isAdmin() || (
                $user->hasPermission(User::PERM_EDIT_CONVERSATIONS)
                && $thread->conversation->userHasAccessToMailbox($user->id)
                && $this->checkIsOnlyAssigned($thread->conversation, $user)
            ));
    }

    public function delete(User $user, Thread $thread)
    {
        // Admin also can delete only own notes.
        if ($thread->created_by_user_id == $user->id) {
            if ($thread->conversation 
                && (!$thread->conversation->userHasAccessToMailbox($user->id) || !$this->checkIsOnlyAssigned($thread->conversation, $user))
            ) {
                return false;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    // If user can see only assigned conversations,
    // check if he/she has access to the conversation.
    public function checkIsOnlyAssigned($conversation, $user)
    {
        // Maybe user can see only assigned conversations.
        if (!\Eventy::filter('conversation.is_user_assignee', $conversation->user_id == $user->id, $conversation, $user->id)
            && $conversation->created_by_user_id != $user->id
            && $user->canSeeOnlyAssignedConversations()
        ) {
            return false;
        } else {
            return true;
        }
    }
}

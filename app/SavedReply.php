<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved reply of a mailbox: text (with variables) and files to put in a
 * reply. A saved reply with saved replies under it is a category. Global
 * ones (and those under them) can be used in every mailbox. One per mailbox
 * can be the default reply template (auto_load).
 */
class SavedReply extends Model
{
    protected $table = 'saved_replies';

    protected $casts = [
        'attachments' => 'array',
        'global'      => 'boolean',
        'auto_load'   => 'boolean',
    ];

    const NAME_MAX_LENGTH = 75;

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * Saved replies of a mailbox, in their order.
     */
    public static function ofMailbox($mailbox_id)
    {
        return self::where('mailbox_id', $mailbox_id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Whether a user may add, change and delete a mailbox's saved replies.
     */
    public static function canManage(User $user, Mailbox $mailbox)
    {
        return $user->isAdmin()
            || ($user->hasPermission(User::PERM_EDIT_SAVED_REPLIES) && in_array($mailbox->id, $user->mailboxesIdsCanView()));
    }

    /**
     * Whether a user may put this saved reply in a reply: in the user's
     * mailboxes, or global (itself or a category above it).
     */
    public function canUse(User $user)
    {
        return in_array($this->mailbox_id, $user->mailboxesIdsCanView()) || $this->isShared();
    }

    /**
     * Global, or under a global category.
     */
    public function isShared()
    {
        $replies = self::where('mailbox_id', $this->mailbox_id)->get()->keyBy('id');
        $reply = $this;
        $seen = [];
        while ($reply && !isset($seen[$reply->id])) {
            if ($reply->global) {
                return true;
            }
            $seen[$reply->id] = true;
            $reply = $replies[$reply->parent_saved_reply_id] ?? null;
        }

        return false;
    }

    /**
     * Saved replies as a tree, flattened: [reply, depth, has children],
     * parents before children.
     */
    public static function tree($replies)
    {
        $children = $replies->groupBy(function ($reply) use ($replies) {
            // Under a parent that isn't in the list: at the top.
            return $reply->parent_saved_reply_id && $replies->contains('id', $reply->parent_saved_reply_id) ? $reply->parent_saved_reply_id : 0;
        });
        $result = [];
        $add = function ($parent_id, $depth) use (&$add, &$result, $children) {
            foreach ($children[$parent_id] ?? [] as $reply) {
                if (isset($result[$reply->id])) {
                    continue;
                }
                $result[$reply->id] = [$reply, $depth, isset($children[$reply->id])];
                $add($reply->id, $depth + 1);
            }
        };
        $add(0, 0);

        return array_values($result);
    }

    /**
     * The saved replies for the editor in a mailbox: the mailbox's own, then
     * the global ones of other mailboxes (with what's under them).
     * Returns [{id, name, depth, category}].
     */
    public static function forEditor(Mailbox $mailbox, User $user)
    {
        $items = [];
        $add = function ($replies) use (&$items) {
            foreach (self::tree($replies) as [$reply, $depth, $has_children]) {
                $items[] = [
                    'id'       => $reply->id,
                    'name'     => $reply->name,
                    'depth'    => $depth,
                    'category' => $has_children,
                ];
            }
        };
        $add(self::ofMailbox($mailbox->id));

        $others = self::where('mailbox_id', '!=', $mailbox->id)->orderBy('sort_order')->orderBy('id')->get()->groupBy('mailbox_id');
        foreach ($others as $replies) {
            $shared = collect();
            foreach (self::tree($replies) as [$reply, $depth, $has_children]) {
                if ($reply->global || $shared->contains('id', $reply->parent_saved_reply_id)) {
                    $shared->push($reply);
                }
            }
            if ($shared->isNotEmpty()) {
                $add($shared);
            }
        }

        return $items;
    }

    /**
     * The text with the variables of a conversation (or a new one in the
     * mailbox) filled in.
     */
    public function render(Mailbox $mailbox, User $user, ?Conversation $conversation = null)
    {
        $customer = null;
        if ($conversation) {
            $customer = $conversation->customer ?: ($conversation->customer_email ? Customer::getByEmail($conversation->customer_email) : null);
        }
        $text = \MailHelper::replaceMailVars((string) $this->text, [
            'conversation' => $conversation,
            'mailbox'      => $mailbox,
            'customer'     => $customer,
            'user'         => $user,
        // Values escaped for HTML; variables without a value stay, to be seen.
        ], true, false);

        return \Helper::stripDangerousTags($text);
    }

    /**
     * Copies of the files, for a reply (the saved reply keeps its own):
     * [{id (encrypted), name, size, url}].
     */
    public function copyAttachments()
    {
        $result = [];
        foreach (Attachment::whereIn('id', (array) $this->attachments)->get() as $attachment) {
            $copy = $attachment->duplicate();
            if ($copy) {
                $result[] = [
                    'id'   => encrypt($copy->id),
                    'name' => $copy->file_name,
                    'size' => $copy->size,
                    'url'  => $copy->url(),
                ];
            }
        }

        return $result;
    }

    /**
     * The default reply template of a mailbox (or a global one).
     */
    public static function template($mailbox_id)
    {
        return self::where('mailbox_id', $mailbox_id)->where('auto_load', true)->first()
            ?: self::where('global', true)->where('auto_load', true)->first();
    }

    /**
     * Delete, with the files; saved replies under it move up a level.
     */
    public function deleteWithAttachments()
    {
        self::where('parent_saved_reply_id', $this->id)->update(['parent_saved_reply_id' => $this->parent_saved_reply_id]);
        Attachment::deleteForever(Attachment::whereIn('id', (array) $this->attachments)->get());
        $this->delete();
    }
}

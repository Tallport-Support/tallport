<?php

namespace App\Observers;

use App\Mailbox;

class MailboxObserver
{
    /**
     * A color of its own (Mailbox::nextAccent()).
     */
    public function creating(Mailbox $mailbox)
    {
        if (!$mailbox->accent) {
            $mailbox->accent = Mailbox::nextAccent();
        }
    }

    /**
     * Listen to the Mailbox created event.
     *
     * @param \App\Mailbox $mailbox
     *
     * @return void
     */
    public function created(Mailbox $mailbox)
    {
        // Create folders
        $mailbox->createPublicFolders();
        $mailbox->syncPersonalFolders();
        $mailbox->createAdminPersonalFolders();
    }

    /**
     * Delete the following on mailbox delete:
     * - folders
     * - conversations
     * - user permissions.
     *
     * @param Mailbox $mailbox
     *
     * @return [type] [description]
     */
    public function deleting(Mailbox $mailbox)
    {
        // Same things are deleted in Mailbox->deleteMailbox().
        $mailbox->users()->delete();
        $mailbox->conversations()->delete();
        $mailbox->folders()->delete();

        \Eventy::action('mailbox.before_delete', $mailbox);
    }

    public function deleted(Mailbox $mailbox)
    {
        foreach (\App\SavedReply::where('mailbox_id', $mailbox->id)->get() as $saved_reply) {
            $saved_reply->deleteWithAttachments();
        }
        \Eventy::action('mailbox.deleted', $mailbox);
    }
}

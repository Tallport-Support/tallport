<?php

namespace App\Incoming;

use App\Mailbox;
use Webklex\PHPIMAP\IMAP;

/**
 * What tallport:fetch-emails does with an email on the mail server once it
 * has it (and marked it as read): leave it, remove it, or move it to an
 * IMAP folder. A mailbox setting (meta "after_fetch"), for IMAP. Tallport
 * keeps each email's source, so nothing is lost on the server.
 */
class AfterFetch
{
    const META = 'after_fetch';

    const LEAVE = 'leave';
    const REMOVE = 'remove';
    const MOVE = 'move';

    /**
     * ['action' => leave|remove|move, 'folder' => '...'].
     */
    public static function settings(Mailbox $mailbox)
    {
        $settings = (array) ($mailbox->getMeta(self::META) ?? []);
        $action = in_array($settings['action'] ?? '', [self::REMOVE, self::MOVE]) ? $settings['action'] : self::LEAVE;
        $folder = trim((string) ($settings['folder'] ?? ''));

        return ['action' => $action == self::MOVE && $folder === '' ? self::LEAVE : $action, 'folder' => $folder];
    }

    public static function save(Mailbox $mailbox, $action, $folder)
    {
        $mailbox->setMeta(self::META, [
            'action' => in_array($action, [self::REMOVE, self::MOVE]) ? $action : self::LEAVE,
            'folder' => trim((string) $folder),
        ]);
    }

    /**
     * Folders to look for an email in: the fetched ones and where they go.
     */
    public static function searchFolders(Mailbox $mailbox)
    {
        $folders = $mailbox->getInImapFolders();
        $settings = self::settings($mailbox);
        if ($settings['action'] == self::MOVE && !in_array($settings['folder'], $folders)) {
            $folders[] = $settings['folder'];
        }

        return $folders;
    }

    /**
     * Remove or move a fetched email (a webklex message or a FetchedMessage).
     * Returns an error, or null.
     */
    public static function apply($message, Mailbox $mailbox)
    {
        $settings = self::settings($mailbox);
        if ($settings['action'] == self::LEAVE || $mailbox->in_protocol != Mailbox::IN_PROTOCOL_IMAP
            || !$message || !$message->getClient()
        ) {
            return null;
        }
        $client = $message->getClient();
        $folder_path = $message->getFolderPath();
        $uid = (int) $message->getUid();
        if ($settings['action'] == self::MOVE && $settings['folder'] == $folder_path) {
            return null;
        }

        try {
            $client->openFolder($folder_path);
            $connection = $client->getConnection();
            if ($settings['action'] == self::MOVE) {
                $folder = \MailHelper::getImapFolder($client, $settings['folder']);
                if (!$folder) {
                    return 'IMAP folder not found on the mail server: '.$settings['folder'];
                }
                $client->openFolder($folder_path);
                try {
                    $connection->moveMessage($folder->path, $uid, null, IMAP::ST_UID)->validatedData();

                    return null;
                } catch (\Throwable $e) {
                    // Without MOVE (RFC 6851): copy, then remove.
                    $connection->copyMessage($folder->path, $uid, null, IMAP::ST_UID)->validatedData();
                }
            }
            $connection->store(['\\Deleted'], $uid, $uid, '+', true, IMAP::ST_UID)->validatedData();
            self::expunge($connection, $uid);
        } catch (\Throwable $e) {
            return $e->getMessage() ?: get_class($e);
        }

        return null;
    }

    /**
     * Remove the email for good: just it (UIDPLUS), else every email marked
     * as deleted in the folder.
     */
    protected static function expunge($connection, $uid)
    {
        try {
            $connection->requestAndResponse('UID EXPUNGE', [(string) $uid])->validatedData();
        } catch (\Throwable $e) {
            $connection->expunge()->validatedData();
        }
    }
}

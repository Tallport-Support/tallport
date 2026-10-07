<?php

namespace App\Misc;

use App\Mailbox;
use App\SavedReply;
use App\User;
use App\Workflow;

/**
 * A mailbox's settings in Settings › Mailboxes: its page (mailboxes/update) and the further
 * pages it leads to, each with its current value (mailboxes/settings_menu), and the header
 * they share (mailboxes/sidebar_menu).
 */
class MailboxSettings
{
    /**
     * The further pages the user may open, in order: label, url, value (or null), whether
     * the value is a problem, and the routes that are the page (for the header's title).
     */
    public static function pages(Mailbox $mailbox, User $user)
    {
        $pages = [];
        $id = $mailbox->id;
        if ($user->can('update', $mailbox)) {
            if ($user->isAdmin()) {
                $pages[] = self::page(__('Connection Settings'), route('mailboxes.connection', ['id' => $id]), ...self::connection($mailbox), routes: ['mailboxes.connection', 'mailboxes.connection.incoming']);
            }
            if ($user->isAdmin() || $user->hasManageMailboxPermission($id, Mailbox::ACCESS_PERM_PERMISSIONS)) {
                $people = count($mailbox->usersHavingAccess(true));
                $pages[] = self::page(__('Permissions'), route('mailboxes.permissions', ['id' => $id]), trans_choice(':count person|:count people', $people), routes: ['mailboxes.permissions']);
            }
            if ($user->isAdmin() || $user->hasManageMailboxPermission($id, Mailbox::ACCESS_PERM_AUTO_REPLIES)) {
                $pages[] = self::page(__('Auto Reply'), route('mailboxes.auto_reply', ['id' => $id]), $mailbox->auto_reply_enabled ? __('On') : __('Off'), routes: ['mailboxes.auto_reply']);
            }
            if ($user->isAdmin()) {
                $pages[] = self::page(__('Telegram'), route('mailboxes.telegram', ['id' => $id]), \App\Telegram\Telegram::isEnabled($mailbox) ? __('Connected') : __('Not set up'), routes: ['mailboxes.telegram']);
            }
            $nostr = \App\Nostr\NostrMailbox::where('mailbox_id', $id)->where('enabled', true)->whereNotNull('pubkey')->exists();
            $pages[] = self::page('Nostr', route('mailboxes.nostr', ['id' => $id]), $nostr ? __('Connected') : __('Not set up'), routes: ['mailboxes.nostr']);
        }
        if ($user->isAdmin()) {
            $pages[] = self::page(__('AI Assistant'), route('mailboxes.ai', ['id' => $id]), \App\Ai\Settings::mailboxSummary($mailbox), routes: ['mailboxes.ai']);
        }
        if (Workflow::canEdit($user, $mailbox)) {
            $pages[] = self::page(__('Workflows'), route('mailboxes.workflows', ['mailbox_id' => $id, 'from' => 'mailbox']), (string) Workflow::where('mailbox_id', $id)->count(), routes: ['mailboxes.workflows']);
        }
        if (SavedReply::canManage($user, $mailbox)) {
            $pages[] = self::page(__('Saved Replies'), route('mailboxes.saved_replies', ['id' => $id, 'from' => 'mailbox']), (string) SavedReply::where('mailbox_id', $id)->count(), routes: ['mailboxes.saved_replies']);
        }

        return $pages;
    }

    /**
     * Whether the user may open the mailbox's own page (mailboxes/update).
     */
    public static function canOpenMailbox(Mailbox $mailbox, User $user)
    {
        return $user->can('updateSettings', $mailbox) || $user->can('updateEmailSignature', $mailbox);
    }

    /**
     * Where the mailbox's settings start for the user: its page, else the first further page
     * they may open (someone who may only manage its permissions, say).
     */
    public static function url(Mailbox $mailbox, User $user)
    {
        if (self::canOpenMailbox($mailbox, $user)) {
            return route('mailboxes.update', ['id' => $mailbox->id]);
        }

        return self::pages($mailbox, $user)[0]['url'] ?? route('mailboxes.update', ['id' => $mailbox->id]);
    }

    /**
     * How the mailbox receives and sends ("IMAP · SMTP"), or that it isn't set up (a problem).
     */
    public static function connection(Mailbox $mailbox)
    {
        if (!$mailbox->isConnected()) {
            return [__('Not set up'), true];
        }
        $in = $mailbox->isDeliveredByMailServer() ? __('Mail Server') : strtoupper((string) $mailbox->getInProtocolName());
        $out = [Mailbox::OUT_METHOD_PHP_MAIL => 'PHP mail()', Mailbox::OUT_METHOD_SENDMAIL => 'Sendmail', Mailbox::OUT_METHOD_SMTP => 'SMTP'][$mailbox->out_method] ?? '';

        return [implode(' · ', array_filter([$in, $out])), false];
    }

    /**
     * A page's title in the header: the further page that is the current route, else null
     * (the mailbox's own page, or a module's: its link in mailboxes.settings.menu).
     */
    public static function currentTitle(Mailbox $mailbox, User $user, $route)
    {
        foreach (self::pages($mailbox, $user) as $page) {
            foreach ($page['routes'] as $page_route) {
                if ($route == $page_route || str_starts_with((string) $route, $page_route.'.')) {
                    return $page['label'];
                }
            }
        }
        // A module's page: its link marked current.
        if (preg_match('#<a\b[^>]*aria-current="page"[^>]*>(.*?)</a>#s', self::modulePages($mailbox), $match)) {
            return trim(html_entity_decode(strip_tags($match[1])));
        }

        return null;
    }

    /**
     * Modules' pages of the mailbox (mailboxes.settings.menu), as rows.
     */
    public static function modulePages(Mailbox $mailbox)
    {
        ob_start();
        \Eventy::action('mailboxes.settings.menu', $mailbox);

        return (string) ob_get_clean();
    }

    protected static function page($label, $url, $value = null, $problem = false, array $routes = [])
    {
        return ['label' => $label, 'url' => $url, 'value' => $value, 'problem' => $problem, 'routes' => $routes];
    }
}

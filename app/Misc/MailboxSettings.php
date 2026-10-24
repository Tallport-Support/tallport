<?php

namespace App\Misc;

use App\Mailbox;
use App\SavedReply;
use App\User;
use App\Workflow;

/**
 * A mailbox's settings, in the mailbox (/mailbox/support/settings): General (mailboxes/update) and its
 * further pages, listed beside the open one with their current values (mailboxes/settings_menu,
 * mailboxes/sidebar_menu).
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
        // Their addresses by the mailbox's short name.
        $key = $mailbox->getSlug();
        if ($user->can('update', $mailbox)) {
            if ($user->isAdmin()) {
                $pages[] = self::page(__('Connection Settings'), route('mailboxes.connection', ['id' => $key]), ...self::connection($mailbox), routes: ['mailboxes.connection', 'mailboxes.connection.incoming']);
            }
            if ($user->isAdmin() || $user->hasManageMailboxPermission($id, Mailbox::ACCESS_PERM_PERMISSIONS)) {
                $people = count($mailbox->usersHavingAccess(true));
                $pages[] = self::page(__('Permissions'), route('mailboxes.permissions', ['id' => $key]), trans_choice(':count person|:count people', $people), routes: ['mailboxes.permissions']);
            }
            if ($user->isAdmin() || $user->hasManageMailboxPermission($id, Mailbox::ACCESS_PERM_AUTO_REPLIES)) {
                $pages[] = self::page(__('Auto Reply'), route('mailboxes.auto_reply', ['id' => $key]), $mailbox->auto_reply_enabled ? __('On') : __('Off'), routes: ['mailboxes.auto_reply']);
            }
            if ($user->isAdmin()) {
                $pages[] = self::page(__('Website Chat'), route('mailboxes.web_chat', ['id' => $key]), \App\WebChat\WebChat::settings($mailbox)['enabled'] ? __('On') : __('Off'), routes: ['mailboxes.web_chat'], chat: true);
                $pages[] = self::page(__('Telegram'), route('mailboxes.telegram', ['id' => $key]), \App\Telegram\Telegram::isEnabled($mailbox) ? __('Connected') : __('Not set up'), routes: ['mailboxes.telegram'], chat: true);
            }
            $matrix = \App\Matrix\MatrixMailbox::forMailbox($id);
            $pages[] = self::page('Matrix', route('mailboxes.matrix', ['id' => $key]), $matrix && $matrix->isReady() ? __('Connected') : __('Not set up'), routes: ['mailboxes.matrix'], chat: true);
            $nostr = \App\Nostr\NostrMailbox::where('mailbox_id', $id)->where('enabled', true)->whereNotNull('pubkey')->exists();
            $pages[] = self::page('Nostr', route('mailboxes.nostr', ['id' => $key]), $nostr ? __('Connected') : __('Not set up'), routes: ['mailboxes.nostr'], chat: true);
        }
        if ($user->isAdmin()) {
            $pages[] = self::page(__('AI'), route('mailboxes.ai', ['id' => $key]), \App\Ai\Settings::mailboxSummary($mailbox), routes: ['mailboxes.ai']);
            $pages[] = self::page(__('Documentation'), route('mailboxes.documentation', ['id' => $key]), trans_choice('1 page|:count pages', \App\Ai\Document::where('mailbox_id', $id)->where('source_type', '!=', \App\Ai\Document::SOURCE_TYPE_FOLDER)->count()), routes: ['mailboxes.documentation']);
        }
        if (Workflow::canEdit($user, $mailbox)) {
            $pages[] = self::page(__('Workflows'), route('mailboxes.workflows', ['mailbox_id' => $key]), (string) Workflow::where('mailbox_id', $id)->count(), routes: ['mailboxes.workflows']);
        }
        if (SavedReply::canManage($user, $mailbox)) {
            $pages[] = self::page(__('Saved Replies'), route('mailboxes.saved_replies', ['id' => $key]), (string) SavedReply::where('mailbox_id', $id)->count(), routes: ['mailboxes.saved_replies']);
        }

        return $pages;
    }

    /**
     * The mailboxes in Settings › Mailboxes for the user: those they may manage.
     */
    public static function listFor(User $user)
    {
        $mailboxes = $user->mailboxesCanView();
        if (!\Eventy::filter('user.can_view_mailbox_menu', false, $user)) {
            $mailboxes = $mailboxes->filter(fn ($mailbox) => $user->canManageMailbox($mailbox));
        }

        return $mailboxes;
    }

    /**
     * Whether the user may open the mailbox's General settings (mailboxes/update).
     */
    public static function canOpenMailbox(Mailbox $mailbox, User $user)
    {
        return $user->can('updateSettings', $mailbox) || $user->can('updateEmailSignature', $mailbox);
    }

    /**
     * Where the mailbox's settings start for the user: General, else the first further page
     * they may open (someone who may only manage its permissions, say).
     */
    public static function url(Mailbox $mailbox, User $user)
    {
        if (self::canOpenMailbox($mailbox, $user)) {
            return route('mailboxes.update', ['id' => $mailbox->getSlug()]);
        }

        return self::pages($mailbox, $user)[0]['url'] ?? route('mailboxes.update', ['id' => $mailbox->getSlug()]);
    }

    /**
     * The open settings page's own address, by the mailbox's short name (opened by its ID, an older
     * link: the page says it, layouts/app's data-page-url); null for a module's page.
     */
    public static function pageUrl(Mailbox $mailbox)
    {
        $route = request()->route();
        if (!$route || !in_array('mailbox.address', $route->gatherMiddleware())) {
            return null;
        }
        $parameters = $route->parameters();
        $parameters[array_key_exists('mailbox_id', $parameters) ? 'mailbox_id' : 'id'] = $mailbox->getSlug();
        $query = request()->getQueryString();

        return route($route->getName(), $parameters).($query ? '?'.$query : '');
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
        $out = [Mailbox::OUT_METHOD_PHP_MAIL => 'PHP mail()', Mailbox::OUT_METHOD_SENDMAIL => 'Sendmail', Mailbox::OUT_METHOD_SMTP => 'SMTP'][$mailbox->out_method]
            ?? \App\Misc\MailProviders::NAMES[(string) $mailbox->getOutProvider()] ?? '';

        return [implode(' · ', array_filter([$in, $out])), false];
    }

    /**
     * A page's title in the header: the further page that is the current route, or a module's
     * (its link in mailboxes.settings.menu), else null (General).
     */
    public static function currentTitle(Mailbox $mailbox, User $user, $route)
    {
        // A module's page: its link marked current (its route may start like one of ours).
        if (preg_match('#<a\b[^>]*aria-current="page"[^>]*>(.*?)</a>#s', self::modulePages($mailbox), $match)) {
            return trim(html_entity_decode(strip_tags($match[1])));
        }
        foreach (self::pages($mailbox, $user) as $page) {
            foreach ($page['routes'] as $page_route) {
                if ($route == $page_route || str_starts_with((string) $route, $page_route.'.')) {
                    return $page['label'];
                }
            }
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

    protected static function page($label, $url, $value = null, $problem = false, array $routes = [], $chat = false)
    {
        return ['label' => $label, 'url' => $url, 'value' => $value, 'problem' => $problem, 'routes' => $routes, 'chat' => $chat];
    }
}

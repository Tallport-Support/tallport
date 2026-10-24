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
     * the value is a problem, the routes that are the page (for the header's title), and whether
     * it's a channel's (Email, Website Chat, Telegram…).
     */
    public static function pages(Mailbox $mailbox, User $user)
    {
        $pages = [];
        $id = $mailbox->id;
        // Their addresses by the mailbox's short name.
        $key = $mailbox->getSlug();
        if ($user->can('update', $mailbox)) {
            if ($user->isAdmin() || $user->hasManageMailboxPermission($id, Mailbox::ACCESS_PERM_PERMISSIONS)) {
                $people = count($mailbox->usersHavingAccess(true));
                $pages[] = self::page(__('Permissions'), route('mailboxes.permissions', ['id' => $key]), trans_choice(':count person|:count people', $people), routes: ['mailboxes.permissions']);
            }
            // Email, a channel like the others: the first of its tabs the user may open (mailboxes/email_menu).
            if ($email_tab = array_key_first(self::emailTabs($mailbox, $user))) {
                // Its address; not set up: none (a choice), or one it can't send or fetch with yet (a problem).
                [$email_value, $email_problem] = !$mailbox->hasEmail() ? [__('Not set up'), false] : ($mailbox->isConnected() ? [$mailbox->email, false] : self::connection($mailbox));
                $pages[] = self::page(__('Email'), route($email_tab, ['id' => $key]), $email_value, $email_problem, routes: ['mailboxes.email', 'mailboxes.connection', 'mailboxes.auto_reply'], channel: true);
            }
            if ($user->isAdmin()) {
                $pages[] = self::page(__('Website Chat'), route('mailboxes.web_chat', ['id' => $key]), \App\WebChat\WebChat::settings($mailbox)['enabled'] ? __('On') : __('Off'), routes: ['mailboxes.web_chat'], channel: true);
                $pages[] = self::page(__('Telegram'), route('mailboxes.telegram', ['id' => $key]), \App\Telegram\Telegram::isEnabled($mailbox) ? __('Connected') : __('Not set up'), routes: ['mailboxes.telegram'], channel: true);
            }
            $matrix = \App\Matrix\MatrixMailbox::forMailbox($id);
            $pages[] = self::page('Matrix', route('mailboxes.matrix', ['id' => $key]), $matrix && $matrix->isReady() ? __('Connected') : __('Not set up'), routes: ['mailboxes.matrix'], channel: true);
            $nostr = \App\Nostr\NostrMailbox::where('mailbox_id', $id)->where('enabled', true)->whereNotNull('pubkey')->exists();
            $pages[] = self::page('Nostr', route('mailboxes.nostr', ['id' => $key]), $nostr ? __('Connected') : __('Not set up'), routes: ['mailboxes.nostr'], channel: true);
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
        return $user->can('updateSettings', $mailbox);
    }

    /**
     * The tabs of the mailbox's Email the user may open, in order (route => label): sending,
     * fetching and auto reply once it has an address.
     */
    public static function emailTabs(Mailbox $mailbox, User $user)
    {
        $email = $mailbox->hasEmail();

        return array_filter([
            'mailboxes.email'               => $user->can('updateSettings', $mailbox) || $user->can('updateEmailSignature', $mailbox) ? __('Address') : null,
            'mailboxes.connection'          => $email && $user->isAdmin() ? __('Sending Emails') : null,
            'mailboxes.connection.incoming' => $email && $user->isAdmin() ? __('Fetching Emails') : null,
            'mailboxes.auto_reply'          => $email && ($user->isAdmin() || $user->hasManageMailboxPermission($mailbox->id, Mailbox::ACCESS_PERM_AUTO_REPLIES)) ? __('Auto Reply') : null,
        ]);
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

    protected static function page($label, $url, $value = null, $problem = false, array $routes = [], $channel = false)
    {
        return ['label' => $label, 'url' => $url, 'value' => $value, 'problem' => $problem, 'routes' => $routes, 'channel' => $channel];
    }
}

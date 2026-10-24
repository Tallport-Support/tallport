<?php

namespace App\Http\Middleware;

use Closure;

/**
 * A mailbox's settings pages (/mailbox/support/settings/website-chat) by its short name, or by its
 * ID (older links, modules' links): the controllers get its ID.
 */
class MailboxAddress
{
    public function handle($request, Closure $next)
    {
        $route = $request->route();
        $name = $route->hasParameter('mailbox_id') ? 'mailbox_id' : 'id';
        $value = (string) $route->parameter($name);
        if ($value !== '' && !ctype_digit($value)) {
            $mailbox = \App\Misc\Addresses::findMailbox($value);
            abort_unless($mailbox, 404);
            $route->setParameter($name, $mailbox->id);
        }

        return $next($request);
    }
}

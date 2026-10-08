<?php

namespace App\Http\Controllers;

use App\Mailbox;
use App\Misc\MailWebhooks;
use Illuminate\Http\Request;

/**
 * Where sending services (Amazon SES by Amazon SNS, Mailgun, Postmark,
 * Resend) send a mailbox's delivery events: one URL per service and mailbox,
 * with the mailbox's webhook secret in it (Mailbox::getOutWebhookUrl()).
 */
class MailWebhooksController extends Controller
{
    public function webhook(Request $request, $provider, $mailbox_id, $token)
    {
        $mailbox = Mailbox::find($mailbox_id);
        if (!$mailbox) {
            return response('Not found.', 404);
        }
        if (!hash_equals($mailbox->getOutWebhookToken(), (string) $token)) {
            return response('Forbidden.', 403);
        }

        [$status, $text] = MailWebhooks::handle($request, $mailbox, $provider);

        return response($text, $status);
    }
}

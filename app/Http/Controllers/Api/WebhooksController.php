<?php

namespace App\Http\Controllers\Api;

use App\Api\Format;
use App\Api\Webhook;
use Illuminate\Http\Request;

class WebhooksController extends ApiController
{
    /**
     * GET /api/webhooks (administrators).
     */
    public function index(Request $request)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        $query = \Eventy::filter('api.webhooks.query', Webhook::orderBy('id'), $request);

        return $this->paginated($request, $query, 'webhooks', function ($webhook) {
            return Format::webhook($webhook);
        });
    }

    /**
     * POST /api/webhooks: url, events, mailboxes (optional).
     */
    public function store(Request $request)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        $url = (string) $this->param($request, 'url');
        if (!$url) {
            return $this->required('url');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || strlen($url) > 255) {
            return $this->error('Invalid URL', 'url');
        }
        $events = $this->param($request, 'events');
        if (!$events) {
            return $this->required('events');
        }
        $events = array_values(array_intersect(is_array($events) ? $events : array_map('trim', explode(',', (string) $events)), Webhook::allEvents()));
        if (!$events) {
            return $this->error('Unknown events. Allowed: '.implode(', ', Webhook::allEvents()), 'events');
        }

        $webhook = new Webhook();
        $webhook->url = $url;
        $webhook->events = $events;
        $webhook->mailboxes = array_values(array_map('intval', (array) $this->param($request, 'mailboxes', []))) ?: null;
        $webhook->save();

        return $this->created(Format::webhook($webhook), $webhook->id);
    }

    /**
     * DELETE /api/webhooks/{id}
     */
    public function destroy(Request $request, $id)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        $webhook = \Eventy::filter('api.webhook.find', Webhook::find($id), $request);
        if (!$webhook) {
            return $this->notFound();
        }
        $webhook->logs()->delete();
        $webhook->delete();

        return $this->noContent();
    }
}

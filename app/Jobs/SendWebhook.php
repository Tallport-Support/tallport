<?php

namespace App\Jobs;

use App\Api\Webhook;
use App\Api\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * POST an event to a webhook. A failed delivery is logged (webhook_logs)
 * and tried again: 2 minutes later, then 4, 6... up to 10 times in all.
 */
class SendWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 60;

    public $webhook_id;

    public $event;

    /**
     * The JSON sent (and signed).
     */
    public $body;

    /**
     * The failed delivery this one tries again.
     */
    public $log_id = null;

    public function __construct($webhook_id, $event, $body, $log_id = null)
    {
        $this->webhook_id = $webhook_id;
        $this->event = $event;
        $this->body = $body;
        $this->log_id = $log_id;
    }

    public function handle()
    {
        $webhook = Webhook::find($this->webhook_id);
        if (!$webhook) {
            return;
        }
        [$status_code, $error] = self::deliver($webhook->url, $this->event, $this->body);

        $webhook->last_run_time = now();
        $webhook->last_run_error = $error;
        $webhook->save();

        $log = $this->log_id ? WebhookLog::find($this->log_id) : null;
        if (!$error) {
            if ($log) {
                $log->status_code = $status_code;
                $log->finished = true;
                $log->save();
            }

            return;
        }

        \Log::error('[Webhook] '.$webhook->url.' ('.$this->event.'): '.$error);
        if ($log) {
            $log->attempts++;
        } else {
            $log = new WebhookLog();
            $log->webhook_id = $webhook->id;
            $log->event = $this->event;
            $log->data = $this->body;
            $log->attempts = 1;
        }
        $log->status_code = $status_code;
        $log->error = $error;
        $log->finished = $log->attempts >= Webhook::MAX_ATTEMPTS;
        $log->save();

        if (!$log->finished) {
            self::dispatch($webhook->id, $this->event, $this->body, $log->id)
                ->onQueue('default')
                ->delay(now()->addMinutes(2 * $log->attempts));
        }
    }

    /**
     * POST the body: [status code (0 without a response), error or ''].
     */
    public static function deliver($url, $event, $body)
    {
        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(['timeout' => 30, 'connect_timeout' => 10]))
                ->withHeaders([
                    'X-FreeScout-Event'     => $event,
                    'X-FreeScout-Signature' => Webhook::sign($body),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            return [0, $e->getMessage() ?: get_class($e)];
        }

        return [$response->status(), $response->successful() ? '' : 'Response status code: '.$response->status()];
    }
}

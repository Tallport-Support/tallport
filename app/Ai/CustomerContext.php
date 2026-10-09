<?php

namespace App\Ai;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Mailbox;
use Illuminate\Support\Facades\Http;

/**
 * Per mailbox: a URL that is sent the customer's email addresses when a
 * reply is drafted and returns JSON about the customer (signed with a
 * secret, like Help Scout's dynamic apps), and guidance for drafting.
 */
class CustomerContext
{
    const HEADERS = ['X-FREESCOUT-SIGNATURE', 'X-HELPSCOUT-SIGNATURE'];

    const MAX_RESPONSE_BYTES = 131072;

    const MAX_PROMPT_CHARS = 12000;

    const MAX_GUIDANCE_CHARS = 6000;

    /**
     * A mailbox's settings: url, secret_key, signature_header, guidance.
     */
    public static function settings(Mailbox $mailbox)
    {
        $get = function ($name) use ($mailbox) {
            return ((array) \Option::get('aiassistant.customer_context_'.$name, []))[$mailbox->id] ?? '';
        };
        $header = $get('signature_header');

        return [
            'url'              => trim((string) $get('url')),
            'secret_key'       => (string) \Helper::decrypt($get('secret_key')),
            'signature_header' => in_array($header, self::HEADERS) ? $header : self::HEADERS[0],
            'guidance'         => trim((string) $get('guidance')),
        ];
    }

    /**
     * For a draft: [status, data, guidance].
     */
    public static function forConversation(Conversation $conversation, ?float $deadline = null)
    {
        $settings = self::settings($conversation->mailbox);
        $result = [
            'status'   => 'disabled',
            'data'     => null,
            'guidance' => mb_substr($settings['guidance'], 0, self::MAX_GUIDANCE_CHARS),
        ];
        if ($settings['url'] === '') {
            return $result;
        }

        try {
            $response = self::post($settings, self::payload($conversation->mailbox, $conversation->customer, self::conversationEmails($conversation), [
                'id'             => (int) $conversation->id,
                'number'         => (int) $conversation->number,
                'subject'        => $conversation->subject,
                'customer_email' => $conversation->customer_email,
            ]), $deadline);
            if ($response['http_status'] < 200 || $response['http_status'] >= 300) {
                throw new \Exception('HTTP error: '.$response['http_status']);
            }
            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                throw new \Exception('Invalid JSON response');
            }
        } catch (\Throwable $e) {
            $result['status'] = 'failed: '.Errors::message($e, 'Customer context for conversation '.$conversation->id.':');

            return $result;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result['status'] = 'available';
        $result['data'] = mb_strlen($json) > self::MAX_PROMPT_CHARS
            ? ['truncated' => true, 'json_excerpt' => mb_substr($json, 0, self::MAX_PROMPT_CHARS)]
            : $data;

        return $result;
    }

    /**
     * Send a test request for an email address, with settings from the form.
     */
    public static function test(Mailbox $mailbox, $email, array $settings)
    {
        $customer = Customer::getByEmail($email);
        $emails = $customer ? $customer->emails->pluck('email')->push($email)->unique()->values()->all() : [$email];

        return self::post($settings, self::payload($mailbox, $customer, $emails, [
            'id'             => null,
            'number'         => null,
            'subject'        => 'Customer context test',
            'customer_email' => $email,
        ]) + ['test' => true]);
    }

    protected static function payload(Mailbox $mailbox, $customer, array $emails, array $conversation)
    {
        return [
            'event'        => 'draft_reply_context',
            'mailbox'      => ['id' => (int) $mailbox->id, 'name' => $mailbox->name, 'email' => $mailbox->email],
            'conversation' => $conversation,
            'customer'     => [
                'id'     => $customer ? (int) $customer->id : null,
                'name'   => $customer ? $customer->getFullName(true, true) : '',
                'emails' => $emails,
            ],
            'emails'       => $emails,
        ];
    }

    protected static function conversationEmails(Conversation $conversation)
    {
        $emails = $conversation->customer ? $conversation->customer->emails->pluck('email')->all() : [];
        foreach (explode(',', (string) $conversation->customer_email) as $email) {
            if ($email = Email::sanitizeEmail(trim($email))) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * POST the payload as JSON, signed: base64 HMAC-SHA1 of the body.
     */
    protected static function post(array $settings, array $payload, ?float $deadline = null)
    {
        if (!Document::isHttpUrl($settings['url'] ?? '')) {
            throw new \Exception('The customer context URL must be an http or https URL');
        }
        $json = json_encode($payload);
        $header = in_array($settings['signature_header'] ?? '', self::HEADERS) ? $settings['signature_header'] : self::HEADERS[0];
        $signature = base64_encode(hash_hmac('sha1', $json, (string) ($settings['secret_key'] ?? ''), true));

        $remaining = $deadline === null ? 15 : (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new \RuntimeException('AI draft deadline exceeded');
        }
        $limited = OutboundHttp::limitedResponseOptions(self::MAX_RESPONSE_BYTES, $too_large);
        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(array_merge([
                'timeout'         => min(15, $remaining),
                'connect_timeout' => min(5, $remaining),
                'allow_redirects' => false,
            ], $limited)))
                ->withUserAgent('Tallport-AI-Assistant')
                ->withHeaders([$header => $signature, 'Accept' => 'application/json', 'Accept-Encoding' => 'identity'])
                ->withBody($json, 'application/json')
                ->post($settings['url']);
        } catch (\Throwable $e) {
            if ($too_large) {
                throw new \RuntimeException('JSON response is too large', 0, $e);
            }
            throw $e;
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('JSON response is too large');
        }

        return [
            'http_status'      => $response->status(),
            'body'             => $body,
            'payload'          => $json,
            'signature_header' => $header,
            'signature'        => $signature,
        ];
    }
}

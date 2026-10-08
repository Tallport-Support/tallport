<?php

namespace Tests\Concerns;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sending services' APIs (App\Misc\MailProviders) without the services:
 * mail is sent by the real transports, to a Symfony MockHttpClient that
 * records each request and answers as the service would.
 */
trait FakesMailProviders
{
    /**
     * Requests the services got: [method, url, headers (lower case name => value), body].
     *
     * @var array
     */
    protected $provider_requests = [];

    /**
     * Stop capturing sent mail (InteractsWithMail) and answer the services'
     * requests with $answer($method, $url, $options), a MockResponse; by
     * default as each service answers a sent email.
     */
    protected function fakeMailProviders(?callable $answer = null)
    {
        \Eventy::removeAllActions('mail.reapply_mail_config');
        \MailHelper::$last_mail_config_hash = '';
        $this->provider_requests = [];

        $this->app->instance('mail.http_client', new MockHttpClient(function ($method, $url, $options) use ($answer) {
            $headers = [];
            foreach ($options['headers'] ?? [] as $header) {
                [$name, $value] = array_map('trim', explode(':', $header, 2));
                $headers[strtolower($name)] = $value;
            }
            $body = $options['body'] ?? '';
            if (is_callable($body)) {
                $chunks = '';
                while ('' !== ($chunk = $body(16372))) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $this->provider_requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) $body];

            return $answer ? $answer($method, $url, $options) : self::providerAnswer($url, (string) $body);
        }));
    }

    /**
     * How each service answers a sent email. Mailgun keeps the email's Message-ID and answers with it.
     */
    protected static function providerAnswer($url, $body = '')
    {
        if (str_contains($url, 'amazonaws.com')) {
            return new MockResponse(json_encode(['MessageId' => '010001a2b3c4d5e6-11111111-2222-3333-4444-555555555555-000000']), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_contains($url, 'mailgun.net')) {
            preg_match('/^Message-ID: (<[^>]+>)/mi', $body, $m);

            return new MockResponse(json_encode(['id' => $m[1] ?? '<20261008120000.1.ABCDEF@mg.example.net>', 'message' => 'Queued. Thank you.']), ['http_code' => 200]);
        }
        if (str_contains($url, 'postmarkapp.com')) {
            return new MockResponse(json_encode(['To' => 'casey@customer.example.org', 'ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817']), ['http_code' => 200]);
        }
        if (str_contains($url, 'resend.com')) {
            return new MockResponse(json_encode(['id' => '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794']), ['http_code' => 200]);
        }

        return new MockResponse('Not found', ['http_code' => 404]);
    }

    /**
     * Set the mailbox up to send through a service's API.
     */
    protected function useMailProvider(\App\Mailbox $mailbox, $out_method, array $settings)
    {
        $mailbox->out_method = $out_method;
        \App\Misc\MailProviders::saveMailboxSettings($mailbox, $settings);
        $mailbox->save();

        return $mailbox;
    }
}

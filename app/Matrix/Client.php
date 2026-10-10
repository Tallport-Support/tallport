<?php

namespace App\Matrix;

use Illuminate\Support\Facades\Http;

class Client
{
    const MAX_JSON_BYTES = 8 * 1024 * 1024;
    const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public static $deadline;

    private $homeserver;
    private $token;

    public static function homeserverUrl($homeserver)
    {
        $homeserver = trim($homeserver);

        return rtrim(str_contains($homeserver, '://') ? $homeserver : 'https://'.$homeserver, '/');
    }

    public function __construct($homeserver, #[\SensitiveParameter] $token = '')
    {
        $homeserver = self::homeserverUrl($homeserver);
        $parts = parse_url($homeserver);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new MatrixException('Invalid Matrix homeserver URL.');
        }
        $this->homeserver = rtrim($homeserver, '/');
        $this->token = $token;
    }

    public function call($method, $path, #[\SensitiveParameter] array $data = [])
    {
        $response = $this->request($method, '/_matrix/client/'.$path, $data, self::MAX_JSON_BYTES);
        $result = Crypto\CanonicalJson::decode($response);
        if ($result instanceof \stdClass && !(array) $result) {
            $result = [];
        }
        if (!is_array($result)) {
            throw new MatrixException('Invalid Matrix server response.');
        }

        return $result;
    }

    public function upload($bytes)
    {
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new MatrixException('Matrix file is too large.');
        }
        $result = json_decode($this->request('POST', '/_matrix/media/v3/upload', [], self::MAX_JSON_BYTES, $bytes), true, 128, JSON_THROW_ON_ERROR);
        if (!is_string($result['content_uri'] ?? null)) {
            throw new MatrixException('Invalid Matrix media response.');
        }

        return $result['content_uri'];
    }

    public function download($mxc)
    {
        if (!is_string($mxc) || !preg_match('~\Amxc://([a-zA-Z0-9.\-:\[\]]+)/([a-zA-Z0-9_\-]+)\z~D', $mxc, $matches)) {
            throw new MatrixException('Invalid Matrix media URI.');
        }

        return $this->request('GET', '/_matrix/client/v1/media/download/'.rawurlencode($matches[1]).'/'.rawurlencode($matches[2]), [], self::MAX_FILE_BYTES);
    }

    private function request($method, $path, #[\SensitiveParameter] array $data, $limit, #[\SensitiveParameter] $bytes = null)
    {
        if (!str_starts_with($path, '/_matrix/') || str_contains($path, '..') || preg_match('/[\x00-\x20\\\\]/', $path)) {
            throw new MatrixException('Invalid Matrix request path.');
        }
        if (self::$deadline !== null && microtime(true) + 25 > self::$deadline) {
            throw new MatrixException('Matrix work will continue on the next sync.');
        }
        $url = $this->homeserver.$path;
        $this->checkAddress($url);
        $host = trim(parse_url($url, PHP_URL_HOST), '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : \Helper::resolveHost($host);
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        if (!$addresses) {
            throw new MatrixException('Matrix homeserver could not be resolved.');
        }
        foreach ($addresses as $address) {
            $ip_url = 'https://'.(str_contains($address, ':') ? '['.$address.']' : $address);
            $this->checkAddress($ip_url);
        }
        try {
            $request = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions([
                'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', array_map(fn ($address) => str_contains($address, ':') ? '['.$address.']' : $address, $addresses))]],
                'progress' => function ($total, $downloaded) use ($limit) {
                    if ($total > $limit || $downloaded > $limit) {
                        throw new MatrixException('Matrix response is too large.');
                    }
                },
            ]);
            if ($this->token !== '') {
                $request = $request->withToken($this->token);
            }
            if ($bytes !== null) {
                $response = $request->withBody($bytes, 'application/octet-stream')->send($method, $url);
            } else {
                $response = $request->send($method, $url, [$method === 'GET' ? 'query' : 'json' => $method === 'GET' ? $data : ($data ?: (object) [])]);
            }
        } catch (\Throwable $e) {
            preg_match('/\bcURL error (\d{1,3}):/', $e->getMessage(), $curl_error);
            \Log::error('Matrix transport failed.', ['method' => $method, 'path' => $path, 'exception' => get_class($e), 'curl_error' => isset($curl_error[1]) ? (int) $curl_error[1] : null]);
            throw new MatrixException('Matrix request failed.', 0, 0, __('Could not reach the Matrix homeserver. Check the connection and try again.'));
        }
        if (strlen($response->body()) > $limit) {
            throw new MatrixException('Matrix response is too large.');
        }
        if (!$response->successful()) {
            $code = $response->json('errcode');
            $code = is_string($code) && preg_match('/\AM_[A-Z_]{1,64}\z/D', $code) ? $code : 'HTTP '.$response->status();
            \Log::error('Matrix request failed.', ['method' => $method, 'path' => $path, 'status' => $response->status(), 'code' => $code]);
            $message = match (true) {
                $code === 'M_USER_DEACTIVATED' => __('The Matrix account has been deactivated. Contact the homeserver administrator.'),
                $response->status() === 429 => __('The homeserver is rate limiting requests. Wait a moment and try again.'),
                $response->status() >= 500 => __('The Matrix homeserver could not complete the request. Try again later.'),
                $method === 'POST' && $path === '/_matrix/client/v3/login' && $code === 'M_FORBIDDEN'
                    => __('The homeserver rejected the login. Check the Matrix account and password.'),
                in_array($code, ['M_UNKNOWN_TOKEN', 'M_MISSING_TOKEN'], true) => __('The Matrix session is no longer valid. Sign in again.'),
                default => __('The Matrix homeserver rejected the request.'),
            };
            $details = str_starts_with($code, 'M_') ? 'HTTP '.$response->status().', '.$code : $code;
            throw new MatrixException('Matrix '.$code.'.', $response->status(), min(3600, max(0, (int) ceil(($response->json('retry_after_ms') ?? 0) / 1000))), $message.' ('.$details.')');
        }

        return $response->body();
    }

    private function checkAddress($url)
    {
        try {
            if (!\Helper::checkUrlIpAndHost($url, true)) {
                throw new MatrixException('Matrix homeserver is not allowed.');
            }
        } catch (\Exception $e) {
            throw new MatrixException($e->getMessage(), $e->getCode());
        }
    }

    public function __debugInfo()
    {
        return ['homeserver' => $this->homeserver];
    }
}

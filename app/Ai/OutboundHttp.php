<?php

namespace App\Ai;

use Psr\Http\Message\ResponseInterface;

/**
 * Limits external AI integration responses while they are being received.
 */
class OutboundHttp
{
    /**
     * Pin a checked address to the connection so DNS cannot change after validation.
     */
    public static function connectionOptions($url)
    {
        if (!Document::isHttpUrl($url) || !\Helper::checkUrlIpAndHost($url)) {
            throw new \Exception(__('Only public http and https URLs can be fetched.'));
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['proxy' => ''];
        }

        $addresses = \Helper::resolveHost($host);
        if (!$addresses) {
            throw new \RuntimeException('Unable to resolve external host');
        }
        foreach ($addresses as $address) {
            $ip_url = 'http://'.(str_contains($address, ':') ? '['.$address.']' : $address).'/';
            if (!\Helper::checkUrlIpAndHost($ip_url)) {
                throw new \Exception(__('Only public http and https URLs can be fetched.'));
            }
        }

        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return ['proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address]]];
    }

    public static function limitedResponseOptions($limit, &$too_large)
    {
        $too_large = false;

        return [
            // The byte limit applies to the representation we read, so do not accept a
            // compressed response that could expand after the transfer completes.
            'decode_content' => false,
            'on_headers'     => function (ResponseInterface $response) use ($limit, &$too_large) {
                $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                if ($encoding !== '' && $encoding !== 'identity') {
                    throw new \RuntimeException('Compressed response is not supported');
                }
                $length = trim($response->getHeaderLine('Content-Length'));
                if (ctype_digit($length) && (float) $length > $limit) {
                    $too_large = true;
                    throw new \RuntimeException('Response is too large');
                }
            },
            'progress' => function ($total, $downloaded) use ($limit, &$too_large) {
                if ($downloaded > $limit) {
                    $too_large = true;

                    return true;
                }

                return false;
            },
        ];
    }
}

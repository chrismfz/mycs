<?php

namespace Nixpal\DomainTools\Http;

/** GET over cURL: HTTPS only, no redirects to other schemes, bounded size and time. */
final class CurlTransport implements Transport
{
    const MAX_BYTES = 1048576;

    public function get($url, array $headers = [], $timeout = 8)
    {
        if (stripos((string) $url, 'https://') !== 0) {
            return [0, ''];
        }
        $body = '';
        $ch = curl_init((string) $url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, (int) $timeout),
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_USERAGENT => 'nixpal-domain-tools/1.0',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$ok === false ? 0 : $status, $body];
    }
}

<?php

namespace MyCS\GrEpp\Http;

/**
 * POST over HTTPS with curl: the peer verified (the registry's chain is HARICA, in the system store), no redirects,
 * HTTPS only, the Set-Cookie headers kept for the session. A CA file pins the registry's chain if given
 * (resources/ca/ca-bundle.pem).
 */
final class CurlTransport implements Transport
{
    /** curl errors before anything was sent: name not resolved, no connection, TLS failed or not trusted. */
    private const NEVER_SENT = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR,
        60 /* CURLE_PEER_FAILED_VERIFICATION */, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_CERTPROBLEM, CURLE_UNSUPPORTED_PROTOCOL];

    public function __construct(
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 10,
        private readonly ?string $caFile = null,
    ) {}

    /** The largest answer read (the registry's are a few KB; a domain list is not an EPP command here). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function post(string $url, #[\SensitiveParameter] string $body, array $headers): HttpResponse
    {
        $lines = ['Expect:']; // no «100-continue» wait on a body over 1 KB
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $cookies = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', // never an https_proxy from the environment: straight to the registry (its IP whitelist)
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => fn ($ch, int $downloadTotal, int $downloaded): int => $downloaded > self::MAX_BYTES ? 1 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$cookies): int {
                if (stripos($line, 'set-cookie:') === 0) {
                    $cookies[] = trim(substr($line, 11));
                }

                return strlen($line);
            },
        ];
        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            // Nothing of the request written (a connect timeout, adversarial ADV-4): never sent.
            $nothingSent = (int) curl_getinfo($ch, CURLINFO_REQUEST_SIZE) === 0;

            return new HttpResponse(0, '', [], $error !== '' ? $error : 'transport error', $nothingSent || in_array($errno, self::NEVER_SENT, true));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return new HttpResponse($status, (string) $response, $cookies);
    }
}

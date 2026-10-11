<?php

namespace Nixpal\Mailcow\Http;

final class CurlTransport implements Transport
{
    /** @var bool */
    private $tlsVerify;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    public function __construct($tlsVerify = true, $connectTimeout = 10, $timeout = 30)
    {
        $this->tlsVerify = (bool) $tlsVerify;
        $this->connectTimeout = max(1, (int) $connectTimeout);
        $this->timeout = max(1, (int) $timeout);
    }

    public function send($method, $url, array $headers, $body = null)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $response = json_encode(['type' => 'error', 'msg' => 'transport: ' . curl_error($ch)]);
        }
        curl_close($ch);

        return [$status, (string) $response];
    }
}

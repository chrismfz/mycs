<?php

namespace Nixpal\MyOpenprovider\Http;

final class CurlTransport implements Transport
{
    /** @var int */
    private $timeout;

    public function __construct($timeout = 30)
    {
        $this->timeout = (int) $timeout;
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
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $response = json_encode(['code' => -1, 'desc' => 'transport error: ' . curl_error($ch)]);
        }
        curl_close($ch);

        return [$status, (string) $response];
    }
}

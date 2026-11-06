<?php

namespace MyCS\Proxmox\Console;

use Closure;
use CurlHandle;
use CurlMultiHandle;

/**
 * HTTPS requests that never hold the relay's loop (ADV-506-3): begun now, moved on by `poll()` once a turn, each one's
 * `done` called with its answer when it ends. curl_multi, nothing else. Not final: a test answers in its place.
 */
class AsyncHttp
{
    /** A request this long is over: the relay's own HANDSHAKE and RECHECK are its timeouts' scale. */
    public const TIMEOUT = 8;

    /** An answer larger than this is no Proxmox or MyCS answer: refused, not kept. */
    public const MAX_BODY = 256 * 1024;

    private CurlMultiHandle $multi;

    /** @var array<int, array{handle: CurlHandle, done: Closure(int, string, string|null): void, body: string}> */
    private array $running = [];

    public function __construct()
    {
        $this->multi = curl_multi_init();
    }

    /**
     * @param  array<int, string>  $headers  «Name: value» lines
     * @param  array{ip?: string|null, self_signed?: bool}  $tls  the host's own IP (its name kept for TLS), and a self-signed certificate accepted only with it
     * @param  Closure(int, string, string|null): void  $done  status (0 when none), body, error
     */
    public function request(string $method, string $url, array $headers, string $body, array $tls, Closure $done): void
    {
        $handle = curl_init();
        $id = spl_object_id($handle);
        $this->running[$id] = ['handle' => $handle, 'done' => $done, 'body' => ''];
        $options = [
            CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => function (CurlHandle $h, string $chunk) use ($id): int {
                $this->running[$id]['body'] .= $chunk;

                return strlen($this->running[$id]['body']) > self::MAX_BODY ? 0 : strlen($chunk);
            },
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $parts = parse_url($url);
        $ip = $tls['ip'] ?? null;
        if ($ip !== null && $ip !== '' && is_array($parts) && isset($parts['host'])) {
            $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $options[CURLOPT_RESOLVE] = [trim($parts['host'], '[]') . ':' . $port . ':' . $ip];
        }
        // A self-signed certificate only with the node's IP (PanelConnection): its name then proves nothing anyway.
        $selfSigned = ($tls['self_signed'] ?? false) && $ip !== null && $ip !== '';
        $options[CURLOPT_SSL_VERIFYPEER] = !$selfSigned;
        $options[CURLOPT_SSL_VERIFYHOST] = $selfSigned ? 0 : 2;
        curl_setopt_array($handle, $options);
        curl_multi_add_handle($this->multi, $handle);
    }

    /** The requests moved on, each ended one's `done` called: true while some are still running. */
    public function poll(): bool
    {
        if ($this->running === []) {
            return false;
        }
        do {
            $status = curl_multi_exec($this->multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'];
            $id = spl_object_id($handle);
            $request = $this->running[$id] ?? null;
            unset($this->running[$id]);
            curl_multi_remove_handle($this->multi, $handle);
            if ($request === null) {
                continue;
            }
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = $info['result'] === CURLE_OK ? null : (curl_error($handle) ?: 'curl error ' . $info['result']);
            try {
                ($request['done'])($error === null ? $code : 0, $error === null ? $request['body'] : '', $error);
            } catch (\Throwable) {
                // One answer's handling that fails never loses the others' (they are read on).
            }
        }

        return $this->running !== [];
    }
}

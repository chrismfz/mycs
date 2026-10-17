<?php

namespace App\Services\Domains\Lookup;

use Illuminate\Support\Facades\Http;
use Nixpal\DomainTools\Http\Transport;
use Throwable;

/** The domain-tools HTTP port over Laravel's client (D8a): faked in tests, the app's proxy and timeouts. */
final class LaravelTransport implements Transport
{
    public function get($url, array $headers = [], $timeout = 8)
    {
        $named = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', (string) $header, 2)) + [1 => ''];
            $named[$name] = $value;
        }
        try {
            $response = Http::withHeaders($named)->timeout((int) $timeout)->connectTimeout(4)->get((string) $url);

            return [$response->status(), $response->body()];
        } catch (Throwable) {
            return [0, ''];
        }
    }
}

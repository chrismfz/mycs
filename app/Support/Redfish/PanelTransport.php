<?php

namespace App\Support\Redfish;

use App\Support\PanelConnection;
use Illuminate\Http\Client\ConnectionException;
use MyCS\Redfish\Transport;

/**
 * The package's reads as MyCS sends a panel's: https only, to the BMC's IP when set, no redirects (PanelConnection).
 * Each read waits 8 seconds at most: five of them still end inside a page request, with «did not answer» for staff
 * instead of a gateway timeout (review of #794).
 */
final class PanelTransport implements Transport
{
    public function __construct(private readonly string $base, private readonly ?string $ip, private readonly bool $selfSigned, private readonly int $timeout = 8) {}

    public function get(string $path, array $headers): array
    {
        try {
            $response = PanelConnection::http($this->base, $this->ip, $this->selfSigned)
                ->connectTimeout(5)->timeout($this->timeout)->withHeaders($headers)->get($this->base . $path);
        } catch (ConnectionException) { // refused, unreachable or too slow
            return [0, ''];
        }

        return [$response->status(), $response->body()];
    }
}

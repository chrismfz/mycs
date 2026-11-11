<?php

namespace App\Support\Redfish;

use App\Support\PanelConnection;
use Illuminate\Http\Client\ConnectionException;
use MyCS\Redfish\Transport;

/**
 * The package's reads as MyCS sends a panel's: https only, to the BMC's IP when set, no redirects (PanelConnection).
 * A BMC answers slowly: each read waits longer than a panel's, and a machine's page never waits for one unasked.
 */
final class PanelTransport implements Transport
{
    public function __construct(private readonly string $base, private readonly ?string $ip, private readonly bool $selfSigned, private readonly int $timeout = 20) {}

    public function get(string $path, array $headers): array
    {
        try {
            $response = PanelConnection::http($this->base, $this->ip, $this->selfSigned)
                ->connectTimeout(5)->timeout($this->timeout)->withHeaders($headers)->get($this->base . $path);
        } catch (ConnectionException) {
            return [0, ''];
        }

        return [$response->status(), $response->body()];
    }
}

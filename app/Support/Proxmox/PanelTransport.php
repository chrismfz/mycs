<?php

namespace App\Support\Proxmox;

use App\Support\PanelConnection;
use Illuminate\Http\Client\ConnectionException;
use MyCS\Proxmox\Transport;

/** The package's requests as MyCS sends a panel's: https only, to each node's IP when set, no redirects (PanelConnection). */
final class PanelTransport implements Transport
{
    /** @param  array<string, string|null>  $ips  base => that node's IP */
    public function __construct(private readonly array $ips, private readonly bool $selfSigned, private readonly int $timeout = 20) {}

    public function send(string $method, string $base, string $path, array $headers): array
    {
        try {
            $response = PanelConnection::http($base, $this->ips[$base] ?? null, $this->selfSigned)
                ->connectTimeout(5)->timeout($this->timeout)->withHeaders($headers)->send($method, $base . $path);
        } catch (ConnectionException) {
            return [0, ''];
        }

        return [$response->status(), $response->body()];
    }
}

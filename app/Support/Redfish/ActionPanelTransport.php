<?php

namespace App\Support\Redfish;

use App\Support\PanelConnection;
use Illuminate\Http\Client\ConnectionException;
use MyCS\Redfish\ActionTransport;

/**
 * The transport of a machine's actions (reset, locate light, boot once): the reads' rules (PanelConnection: https only,
 * to the BMC's IP, no redirects) and a POST or PATCH besides. Built only for an action staff asked for, with the
 * actions' own BMC account; the hourly read never has one.
 */
final class ActionPanelTransport implements ActionTransport
{
    private readonly PanelTransport $reads;

    public function __construct(private readonly string $base, private readonly ?string $ip, private readonly bool $selfSigned, private readonly int $timeout = 15)
    {
        $this->reads = new PanelTransport($base, $ip, $selfSigned);
    }

    public function get(string $path, array $headers): array
    {
        return $this->reads->get($path, $headers);
    }

    public function send(string $method, string $path, array $headers, array $json): array
    {
        try {
            $response = PanelConnection::http($this->base, $this->ip, $this->selfSigned)
                ->connectTimeout(5)->timeout($this->timeout)->withHeaders($headers)->send($method, $this->base . $path, ['json' => $json]);
        } catch (ConnectionException) { // unreachable, or no answer in time: done or not, the caller says so
            return [0, ''];
        }

        return [$response->status(), $response->body()];
    }
}

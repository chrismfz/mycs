<?php

namespace MyCS\Extensions\Servers\Proxmox;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Support\PanelConnection;
use App\Support\Proxmox\PanelTransport;
use MyCS\Proxmox\ProxmoxClient;
use Throwable;

/**
 * A Proxmox VE cluster (owner 2026-10-24), read-only for now: its nodes, their graphs and its guests, each mapped to a
 * service or marked internal (Proxmox guests). One server per cluster, with up to three of its nodes' addresses: any
 * node answers for the whole cluster, so one node down hides nothing. A second cluster or a standalone node is another
 * server. The token needs only PVEAuditor on «/».
 */
#[ExtensionMeta(
    name: 'Proxmox VE',
    description: 'A Proxmox VE cluster: its nodes and guests, read with an API token',
    version: 'builtin',
    author: 'MyCS',
)]
class Proxmox extends Server
{
    public function getConfig($values = []): array
    {
        $ip = PanelConnection::fields()[0];
        $node = fn (int $n) => [
            ['name' => "node{$n}_host", 'type' => 'text', 'label' => "Node {$n} address", 'placeholder' => "https://pve{$n}.example.com:8006",
                'validation' => 'nullable|url:https', 'required' => false, 'description' => 'Tried when the nodes above do not answer.'],
            ['name' => "node{$n}_ip", 'label' => "Node {$n} IP"] + $ip,
        ];

        return [
            ['name' => 'host', 'type' => 'text', 'label' => 'Node address', 'placeholder' => 'https://pve1.example.com:8006',
                'validation' => 'url:https', 'required' => true, 'description' => 'Any node of the cluster: it answers for all of them.'],
            ...PanelConnection::fields(),
            ...$node(2),
            ...$node(3),
            ['name' => 'token_id', 'type' => 'text', 'label' => 'API token ID', 'placeholder' => 'mycs@pve!api', 'required' => true,
                'validation' => ['required', 'regex:/^[^@\s]+@[^!\s]+![^\s]+$/']],
            ['name' => 'token_secret', 'type' => 'password', 'label' => 'API token secret', 'required' => true, 'encrypted' => true],
        ];
    }

    /** The cluster's client: its nodes' addresses in order, each to its own IP. */
    public function client(): ProxmoxClient
    {
        $ips = [];
        foreach ([['host', 'ip'], ['node2_host', 'node2_ip'], ['node3_host', 'node3_ip']] as [$host, $ip]) {
            $base = rtrim(trim((string) $this->config($host)), '/');
            if ($base !== '') {
                $ips[$base] = trim((string) $this->config($ip)) ?: null;
            }
        }

        return new ProxmoxClient(new PanelTransport($ips, (bool) $this->config('self_signed')), array_keys($ips),
            trim((string) $this->config('token_id')), trim((string) $this->config('token_secret')));
    }

    public function testConfig(): bool|string
    {
        try {
            $this->client()->version();
            $this->client()->nodes();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return true;
    }
}

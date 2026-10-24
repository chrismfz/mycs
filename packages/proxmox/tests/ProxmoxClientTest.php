<?php

namespace MyCS\Proxmox\Tests;

use MyCS\Proxmox\Guest;
use MyCS\Proxmox\ProxmoxClient;
use MyCS\Proxmox\ProxmoxException;
use PHPUnit\Framework\TestCase;

final class ProxmoxClientTest extends TestCase
{
    private const VEGA = 'https://vega.example.test:8006';

    private const PEGASUS = 'https://pegasus.example.test:8006';

    private function client(FakeTransport $http): ProxmoxClient
    {
        return new ProxmoxClient($http, [self::VEGA, self::PEGASUS], 'mycs@pve!api', 'secret-uuid');
    }

    public function test_it_sends_the_token(): void
    {
        $http = new FakeTransport(['version' => [200, ['version' => '9.0.3']]]);

        $this->assertSame('9.0.3', $this->client($http)->version()['version']);
        $this->assertSame([self::VEGA, '/api2/json/version'], [$http->sent[0][0], $http->sent[0][1]]);
        $this->assertSame('PVEAPIToken=mycs@pve!api=secret-uuid', $http->sent[0][2]['Authorization']);
    }

    public function test_a_node_down_hides_nothing_and_is_asked_last_afterwards(): void
    {
        $http = new FakeTransport(['nodes' => [200, [['node' => 'vega', 'status' => 'offline'], ['node' => 'pegasus', 'status' => 'online']]]]);
        $http->down[self::VEGA] = true;
        $client = $this->client($http);

        $this->assertCount(2, $client->nodes());
        $this->assertCount(2, $client->nodes());

        $this->assertSame([self::VEGA, self::PEGASUS, self::PEGASUS], array_column($http->sent, 0), 'vega tried once, then pegasus first');
    }

    public function test_a_node_whose_proxy_is_not_serving_fails_over(): void
    {
        $http = new FakeTransport(['nodes' => [200, [['node' => 'pegasus']]]]);
        $http->statuses[self::VEGA] = 503;

        $this->assertCount(1, $this->client($http)->nodes());
        $this->assertSame([self::VEGA, self::PEGASUS], array_column($http->sent, 0));
    }

    public function test_all_nodes_down_says_so(): void
    {
        $http = new FakeTransport;
        $http->down = [self::VEGA => true, self::PEGASUS => true];

        $this->expectExceptionMessage('No node answered');
        $this->client($http)->nodes();
    }

    public function test_a_refused_token_is_not_tried_on_another_node(): void
    {
        $http = new FakeTransport(['nodes' => [401, null]]);

        try {
            $this->client($http)->nodes();
            $this->fail('refused');
        } catch (ProxmoxException $e) {
            $this->assertStringContainsString('token was refused', $e->getMessage());
        }
        $this->assertCount(1, $http->sent);
    }

    public function test_a_token_id_and_an_address_are_required(): void
    {
        $this->expectException(ProxmoxException::class);
        new ProxmoxClient(new FakeTransport, [' ', ''], 'mycs@pve!api', 's');
    }

    public function test_a_malformed_token_id_is_refused(): void
    {
        $this->expectExceptionMessage('user@realm!name');
        new ProxmoxClient(new FakeTransport, [self::VEGA], 'root@pam', 's');
    }

    public function test_guests_with_their_macs_and_addresses(): void
    {
        $http = new FakeTransport([
            'cluster/resources?type=vm' => [200, [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'web1', 'status' => 'running', 'maxcpu' => 4,
                    'maxmem' => 8589934592, 'mem' => 4294967296, 'cpu' => 0.12, 'maxdisk' => 107374182400, 'uptime' => 3600, 'tags' => 'client;web'],
                ['vmid' => 102, 'type' => 'qemu', 'node' => 'pegasus', 'name' => 'db1', 'status' => 'running'],
                ['vmid' => 200, 'type' => 'lxc', 'node' => 'pegasus', 'name' => 'ct1', 'status' => 'stopped'],
                ['vmid' => 9000, 'type' => 'qemu', 'node' => 'vega', 'name' => 'tpl', 'status' => 'stopped', 'template' => 1],
            ]],
            'nodes/vega/qemu/101/config' => [200, ['net0' => 'virtio=bc:24:11:aa:bb:01,bridge=vmbr0,firewall=1', 'net1' => 'e1000=BC:24:11:AA:BB:02,bridge=vmbr1', 'scsi0' => 'local:101/disk.raw']],
            'nodes/vega/qemu/101/agent/network-get-interfaces' => [200, ['result' => [
                ['name' => 'lo', 'ip-addresses' => [['ip-address' => '127.0.0.1']]],
                ['name' => 'eth0', 'ip-addresses' => [['ip-address' => '203.0.113.10'], ['ip-address' => 'fe80::1'], ['ip-address' => '2001:db8::10']]],
            ]]],
            // 102: no agent (the fake answers 500), its config readable
            'nodes/pegasus/qemu/102/config' => [200, ['net0' => 'virtio=BC:24:11:CC:00:01,bridge=vmbr0']],
            'nodes/pegasus/lxc/200/config' => [200, ['net0' => 'name=eth0,bridge=vmbr0,hwaddr=BC:24:11:DD:00:01,ip=203.0.113.20/24,ip6=2001:db8::20/64,type=veth']],
            'nodes/vega/qemu/9000/config' => [200, []],
        ]);

        $guests = $this->client($http)->guests();

        $this->assertCount(4, $guests);
        [$web, $db, $ct, $tpl] = $guests;
        $this->assertInstanceOf(Guest::class, $web);
        $this->assertSame([101, 'qemu', 'vega', 'web1', 4, 8589934592], [$web->vmid, $web->type, $web->node, $web->name, $web->cpus, $web->maxMemory]);
        $this->assertSame(['BC:24:11:AA:BB:01', 'BC:24:11:AA:BB:02'], $web->macs);
        $this->assertSame(['203.0.113.10', '2001:db8::10'], $web->ips);
        $this->assertSame(['client', 'web'], $web->tags);
        $this->assertSame([['BC:24:11:CC:00:01'], []], [$db->macs, $db->ips], 'no agent: listed without addresses');
        $this->assertSame([['BC:24:11:DD:00:01'], ['203.0.113.20', '2001:db8::20']], [$ct->macs, $ct->ips]);
        $this->assertTrue($tpl->template);
        $this->assertNotContains('nodes/pegasus/lxc/200/agent/network-get-interfaces', array_map(fn ($s) => substr($s[1], 11), $http->sent), 'no agent call for a container');
    }

    public function test_a_node_graph(): void
    {
        $http = new FakeTransport(['nodes/vega/rrddata?timeframe=hour&cf=AVERAGE' => [200, [['time' => 1, 'cpu' => 0.1], ['time' => 2, 'cpu' => 0.2]]]]);

        $this->assertCount(2, $this->client($http)->nodeRrd('vega'));
    }
}

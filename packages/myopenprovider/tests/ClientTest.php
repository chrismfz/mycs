<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\Client;
use Nixpal\MyOpenprovider\OpenproviderException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(FakeTransport $http, $store = null, &$log = null)
    {
        $log = [];

        return new Client($http, 'reseller', 'secret-pass', false, $store, function () use (&$log) {
            $log[] = func_get_args();
        });
    }

    public function test_login_once_then_bearer_on_every_call()
    {
        $http = (new FakeTransport)->queueLogin('T1')->queueData(['results' => []])->queueData(['results' => []]);
        $client = $this->client($http, new MemoryTokenStore);
        $client->findDomain('example.com');
        $client->findDomain('example.net');

        $this->assertCount(3, $http->requests);
        $this->assertSame('https://api.openprovider.eu/v1beta/auth/login', $http->requests[0]['url']);
        $this->assertSame(['username' => 'reseller', 'password' => 'secret-pass'], $http->json(0));
        $this->assertArrayNotHasKey('Authorization', $http->requests[0]['headers']);
        $this->assertSame('Bearer T1', $http->requests[1]['headers']['Authorization']);
        $this->assertSame('Bearer T1', $http->requests[2]['headers']['Authorization']);
    }

    public function test_stored_token_is_reused_and_a401_logs_in_again_once()
    {
        $store = new MemoryTokenStore;
        $http = (new FakeTransport)->queueLogin('OLD')->queueData(['results' => []]);
        $this->client($http, $store)->findDomain('example.com');

        $http2 = (new FakeTransport)->queue(401, ['code' => 196, 'desc' => 'Authentication failed'])
            ->queueLogin('NEW')->queueData(['results' => []]);
        $this->client($http2, $store)->findDomain('example.com');

        $this->assertSame('Bearer OLD', $http2->requests[0]['headers']['Authorization']);
        $this->assertStringEndsWith('/v1beta/auth/login', $http2->requests[1]['url']);
        $this->assertSame('Bearer NEW', $http2->requests[2]['headers']['Authorization']);
        $this->assertSame(['NEW'], array_values($store->tokens));
    }

    public function test_find_domain_prefers_the_live_record_and_encodes_the_name()
    {
        $http = (new FakeTransport)->queueLogin()->queueData(['results' => [
            ['id' => 1, 'status' => 'DEL'],
            ['id' => 2, 'status' => 'ACT'],
        ]]);
        $record = $this->client($http)->findDomain(' Example.CO.uk. ');

        $this->assertSame(2, $record['id']);
        $this->assertSame('https://api.openprovider.eu/v1beta/domains?full_name=example.co.uk', $http->last()['url']);
    }

    public function test_find_domain_falls_back_to_the_tombstone_and_null_when_absent()
    {
        $http = (new FakeTransport)->queueLogin()->queueData(['results' => [['id' => 1, 'status' => 'DEL']]])->queueData(['total' => 0]);
        $client = $this->client($http);

        $this->assertSame(1, $client->findDomain('example.com')['id']);
        $this->assertNull($client->findDomain('example.com'));
    }

    public function test_idn_names_are_punycoded()
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl not installed');
        }
        $http = (new FakeTransport)->queueLogin()->queueData(['results' => []]);
        $this->client($http)->findDomain('παράδειγμα.eu');

        $this->assertStringEndsWith('full_name=xn--hxajbheg2az3al.eu', $http->last()['url']);
    }

    public function test_renew_restore_and_autorenew_requests()
    {
        $http = (new FakeTransport)->queueLogin()->queueData(['status' => 'ACT'])->queueData([])->queueData(['success' => true]);
        $client = $this->client($http);
        $client->renewDomain(123, 2);
        $client->restoreDomain('123');
        $client->setAutorenew(123, 'off');

        $this->assertSame(['POST', 'https://api.openprovider.eu/v1beta/domains/123/renew'], [$http->requests[1]['method'], $http->requests[1]['url']]);
        $this->assertSame(['id' => 123, 'period' => 2], $http->json(1));
        $this->assertSame('https://api.openprovider.eu/v1beta/domains/123/restore', $http->requests[2]['url']);
        $this->assertSame(['id' => 123], $http->json(2));
        $this->assertSame('PUT', $http->requests[3]['method']);
        $this->assertSame(['id' => 123, 'autorenew' => 'off'], $http->json(3));
    }

    public function test_invalid_input_never_reaches_the_api()
    {
        $http = new FakeTransport;
        $client = $this->client($http);
        foreach ([
            function () use ($client) {
                $client->renewDomain(1, 0);
            },
            function () use ($client) {
                $client->renewDomain(1, 11);
            },
            function () use ($client) {
                $client->renewDomain('1/../2', 1);
            },
            function () use ($client) {
                $client->setAutorenew(1, 'maybe');
            },
            function () use ($client) {
                $client->findDomain('localhost');
            },
        ] as $call) {
            try {
                $call();
                $this->fail('expected OpenproviderException');
            } catch (OpenproviderException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(0, $http->requests);
    }

    public function test_errors_carry_openproviders_description()
    {
        $http = (new FakeTransport)->queueLogin()->queue(200, ['code' => 399, 'desc' => 'Domain cannot be renewed'])
            ->queue(500, 'oops');
        $client = $this->client($http);
        try {
            $client->renewDomain(5, 1);
            $this->fail('expected exception');
        } catch (OpenproviderException $e) {
            $this->assertStringContainsString('code 399', $e->getMessage());
            $this->assertStringContainsString('Domain cannot be renewed', $e->getMessage());
        }
        $this->expectException(OpenproviderException::class);
        $client->restoreDomain(5);
    }

    public function test_ambiguous_failures_are_marked_as_such()
    {
        $http = (new FakeTransport)->queueLogin()
            ->queue(200, ['code' => 399, 'desc' => 'refused'])      // definitive
            ->queue(400, ['code' => 320, 'desc' => 'bad request'])  // definitive
            ->queue(0, '')                                          // timeout: unknown
            ->queue(502, '<html>bad gateway</html>')                // unknown
            ->queue(200, '<html>maintenance</html>');               // 200 without envelope: unknown
        $client = $this->client($http);
        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            try {
                $client->renewDomain(5, 1);
                $seen[] = 'ok';
            } catch (OpenproviderException $e) {
                $seen[] = $e->isDefinitive() ? 'definitive' : 'unknown';
            }
        }

        $this->assertSame(['definitive', 'definitive', 'unknown', 'unknown', 'unknown'], $seen);
    }

    public function test_failed_login_throws()
    {
        $http = (new FakeTransport)->queue(401, ['code' => 196, 'desc' => 'Authentication failed']);
        $this->expectException(OpenproviderException::class);
        $this->expectExceptionMessage('Authentication failed');
        $this->client($http)->findDomain('example.com');
    }

    public function test_logs_never_contain_the_password_token_or_auth_code()
    {
        $http = (new FakeTransport)->queueLogin('SECRET-TOKEN')->queueData(['id' => 7, 'auth_code' => 'EPP-SECRET', 'status' => 'ACT']);
        $client = $this->client($http, null, $log);
        $client->getDomain(7);

        $all = json_encode($log);
        $this->assertStringNotContainsString('secret-pass', $all);
        $this->assertStringNotContainsString('SECRET-TOKEN', $all);
        $this->assertStringNotContainsString('EPP-SECRET', $all);
        $this->assertCount(2, $log);
    }

    public function test_sandbox_url()
    {
        $client = new Client(new FakeTransport, 'u', 'p', true);
        $this->assertSame('http://api.sandbox.openprovider.nl:8480', $client->baseUrl());
    }

    public function test_zone_records_when_the_zone_exists_and_null_when_not()
    {
        $http = (new FakeTransport)->queueLogin()
            ->queueData(['results' => [['name' => 'example.com.au'], ['name' => 'example.com']]])
            ->queueData(['results' => [['name' => 'www.example.com', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]]]);
        $records = $this->client($http)->getZoneRecords('Example.com');

        $this->assertSame('www.example.com', $records[0]['name']);
        $this->assertSame('https://api.openprovider.eu/v1beta/dns/zones?name_pattern=example.com&limit=10', $http->requests[1]['url']);
        $this->assertSame('https://api.openprovider.eu/v1beta/dns/zones/example.com/records?limit=500&offset=0', $http->requests[2]['url']);

        // Only a similarly named zone (or none): no zone.
        $none = (new FakeTransport)->queueLogin()->queueData(['results' => [['name' => 'example.com.au']]]);
        $this->assertNull($this->client($none)->getZoneRecords('example.com'));
        $this->assertCount(2, $none->requests);
    }

    public function test_create_and_update_zone()
    {
        $http = (new FakeTransport)->queueLogin()->queueData(['success' => true])->queueData(['success' => true]);
        $client = $this->client($http);
        $client->createZone('example.com', [['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]]);
        $client->updateZoneRecords('example.com', [['name' => 'mail', 'type' => 'A', 'value' => '192.0.2.2', 'ttl' => 3600]], [['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]]);

        $this->assertSame(['POST', 'https://api.openprovider.eu/v1beta/dns/zones'], [$http->requests[1]['method'], $http->requests[1]['url']]);
        $this->assertSame(['domain' => ['name' => 'example', 'extension' => 'com'], 'type' => 'master',
            'records' => [['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]]], $http->json(1));
        $this->assertSame(['PUT', 'https://api.openprovider.eu/v1beta/dns/zones/example.com'], [$http->requests[2]['method'], $http->requests[2]['url']]);
        $this->assertSame(['name' => 'example.com', 'records' => [
            'add' => [['name' => 'mail', 'type' => 'A', 'value' => '192.0.2.2', 'ttl' => 3600]],
            'remove' => [['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600]],
        ]], $http->json(2));
    }

    public function test_nameserver_hosts()
    {
        $http = (new FakeTransport)->queueLogin()
            ->queueData(['results' => [['name' => 'ns1.example.com', 'ip' => '192.0.2.1'], ['name' => 'ns1.badexample.com', 'ip' => '192.0.2.9']]])
            ->queueData(['success' => true])->queueData(['success' => true])->queueData(['success' => true]);
        $client = $this->client($http);

        $this->assertSame([['name' => 'ns1.example.com', 'ip' => '192.0.2.1', 'ip6' => '']], $client->listNameservers('Example.com'));
        $this->assertSame('https://api.openprovider.eu/v1beta/dns/nameservers?pattern=%2A.example.com&limit=100', $http->requests[1]['url']);

        $client->saveNameserver(['name' => 'ns2.example.com', 'ip' => '192.0.2.2'], false);
        $client->saveNameserver(['name' => 'ns1.example.com', 'ip6' => '2001:db8::1'], true);
        $client->deleteNameserver('NS1.example.com');
        $this->assertSame(['POST', 'https://api.openprovider.eu/v1beta/dns/nameservers'], [$http->requests[2]['method'], $http->requests[2]['url']]);
        $this->assertSame(['name' => 'ns2.example.com', 'ip' => '192.0.2.2'], $http->json(2));
        $this->assertSame(['PUT', 'https://api.openprovider.eu/v1beta/dns/nameservers/ns1.example.com'], [$http->requests[3]['method'], $http->requests[3]['url']]);
        $this->assertSame(['DELETE', 'https://api.openprovider.eu/v1beta/dns/nameservers/ns1.example.com'], [$http->requests[4]['method'], $http->requests[4]['url']]);
    }

    public function test_suggest_names()
    {
        $http = (new FakeTransport)->queueLogin()->queueData(['results' => [
            ['name' => 'Greek-Bakery.com', 'domain' => 'Greek-Bakery', 'tld' => 'com'],
            ['domain' => 'GreekBakery', 'tld' => 'net'],        // no "name": built from domain + tld
            ['name' => 'greek-bakery.com'],                     // duplicate
            ['name' => 'nodot'],
            'junk',
        ]]);
        $names = $this->client($http)->suggestNames('greek bakery', ['com', 'net'], 99, 'eng', true);

        $this->assertSame(['greek-bakery.com', 'greekbakery.net'], $names);
        $this->assertSame(['POST', 'https://api.openprovider.eu/v1beta/domains/suggest-name'], [$http->requests[1]['method'], $http->requests[1]['url']]);
        $this->assertSame(['name' => 'greek bakery', 'limit' => 50, 'language' => 'eng', 'sensitive' => true, 'tlds' => ['com', 'net']], $http->json(1));
    }
}

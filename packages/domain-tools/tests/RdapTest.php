<?php

namespace Nixpal\DomainTools\Tests;

use Nixpal\DomainTools\Whois\RdapBootstrap;
use Nixpal\DomainTools\Whois\RdapClient;
use PHPUnit\Framework\TestCase;

final class RdapTest extends TestCase
{
    private static function bootstrap()
    {
        return RdapBootstrap::fromJson(json_encode(['version' => '1.0', 'services' => [
            [['com', 'net'], ['http://insecure.example/', 'https://rdap.verisign.example/com/v1/']],
            [['uk'], ['https://rdap.nominet.example/uk']],
            [['onlyhttp'], ['http://rdap.example/']],
        ]]));
    }

    public function test_bootstrap_picks_https_servers_and_parent_tlds()
    {
        $b = self::bootstrap();
        $this->assertSame('https://rdap.verisign.example/com/v1/', $b->serverFor('.COM'));
        $this->assertSame('https://rdap.nominet.example/uk/', $b->serverFor('co.uk'));   // falls back to uk
        $this->assertNull($b->serverFor('onlyhttp'));                                    // no https URL
        $this->assertNull($b->serverFor('gr'));
        $this->assertNull(RdapBootstrap::fromJson('{"nope":1}'));
    }

    public function test_thin_registry_answer_is_completed_from_the_registrar()
    {
        $http = new FakeTransport;
        $http->answers['https://rdap.verisign.example/com/v1/domain/example.com'] = [200, json_encode([
            'objectClassName' => 'domain',
            'ldhName' => 'EXAMPLE.COM',
            'status' => ['client transfer prohibited'],
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-08-13T04:00:00Z'],
            ],
            'nameservers' => [['ldhName' => 'A.IANA-SERVERS.NET'], ['ldhName' => 'B.IANA-SERVERS.NET.']],
            'secureDNS' => ['delegationSigned' => true],
            'entities' => [[
                'roles' => ['registrant'],
                'vcardArray' => ['vcard', [['fn', new \stdClass, 'text', 'Private Person'], ['email', new \stdClass, 'text', 'p@example.org']]],
            ]],
            'links' => [['rel' => 'related', 'type' => 'application/rdap+json', 'href' => 'https://rdap.registrar.example/domain/example.com']],
        ])];
        // The registry gave no registrar (thin): it comes from the registrar's own answer.
        $http->answers['https://rdap.registrar.example/domain/example.com'] = [200, json_encode([
            'objectClassName' => 'domain',
            'events' => [['eventAction' => 'last changed', 'eventDate' => '2026-01-02T03:04:05Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2099-01-01T00:00:00Z']],
            'entities' => [[
                'roles' => ['registrar'],
                'vcardArray' => ['vcard', [['version', new \stdClass, 'text', '4.0'], ['fn', new \stdClass, 'text', 'Example Registrar, Inc.']]],
                'publicIds' => [['type' => 'IANA Registrar ID', 'identifier' => '376']],
            ]],
        ])];

        [$result, $record] = (new RdapClient($http, self::bootstrap()))->lookup('example.com');

        $this->assertSame('found', $result);
        $this->assertSame('example.com', $record->domain);
        $this->assertSame('Example Registrar, Inc.', $record->registrar);   // the registrar, never the registrant
        $this->assertSame('376', $record->registrar_iana_id);
        $this->assertSame('1995-08-14T04:00:00Z', $record->registered);
        $this->assertSame('2027-08-13T04:00:00Z', $record->expires);       // the registry's date wins
        $this->assertSame('2026-01-02T03:04:05Z', $record->changed);       // from the registrar's answer
        $this->assertSame(['a.iana-servers.net', 'b.iana-servers.net'], $record->nameservers);
        $this->assertTrue($record->dnssec);
        $this->assertStringNotContainsString('Private Person', json_encode($record));
        $this->assertStringNotContainsString('p@example.org', json_encode($record));
    }

    public function test_the_registrys_own_word_never_follows_the_registrar()
    {
        $http = new FakeTransport;
        $http->answers['https://rdap.verisign.example/com/v1/domain/example.com'] = [200, json_encode([
            'objectClassName' => 'domain', 'ldhName' => 'EXAMPLE.COM', 'events' => [],
            'links' => [['rel' => 'related', 'type' => 'application/rdap+json', 'href' => 'https://rdap.registrar.example/domain/example.com']],
        ])];
        $http->answers['https://rdap.registrar.example/domain/example.com'] = [200, json_encode([
            'objectClassName' => 'domain', 'events' => [['eventAction' => 'expiration', 'eventDate' => '2026-11-01T00:00:00Z']],
        ])];

        [$result, $record] = (new RdapClient($http, self::bootstrap()))->lookup('example.com', false);

        $this->assertSame('found', $result);
        $this->assertNull($record->expires); // a thin registry's missing date is never the registrar's
    }

    public function test_not_found_unsupported_and_errors()
    {
        $http = new FakeTransport;
        $client = new RdapClient($http, self::bootstrap());
        $this->assertSame(['not_found', null], $client->lookup('free-name.com'));       // 404
        $this->assertSame(['unsupported', null], $client->lookup('example.gr'));
        $this->assertSame([], array_diff($http->requests, ['https://rdap.verisign.example/com/v1/domain/free-name.com']));
        $http->answers['https://rdap.verisign.example/com/v1/domain/broken.com'] = [200, '{"objectClassName":"entity"}'];
        $this->assertSame(['error', null], $client->lookup('broken.com'));
        $this->assertTrue($client->supports('net'));
        $this->assertFalse($client->supports('gr'));
    }
}

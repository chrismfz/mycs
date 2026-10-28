<?php

namespace Nixpal\Mailcow\Tests;

use Nixpal\Mailcow\MailDns;
use PHPUnit\Framework\TestCase;

final class MailDnsTest extends TestCase
{
    private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqOURKEY';

    /** @param array<string, list<string>> $dns «TYPE name» => records */
    private function check(array $dns, string $domain = 'example.org', ?string $dkim = self::DKIM): array
    {
        $rows = (new MailDns(fn (string $name, string $type) => $dns["{$type} {$name}"] ?? [], 'https://mail.example.net', ['192.0.2.25'], 'dkim', $dkim))->check($domain);

        return array_column($rows, null, 'key');
    }

    public function test_a_domain_set_up_as_it_should_be_is_all_ok()
    {
        $rows = $this->check([
            'MX example.org' => ['mail.example.net.'],
            'TXT example.org' => ['google-site-verification=x', 'v=spf1 +a +mx ~all'],
            'TXT dkim._domainkey.example.org' => ['v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkq OURKEY'],
            'TXT _dmarc.example.org' => ['v=DMARC1; p=quarantine'],
            'CNAME autodiscover.example.org' => ['mail.example.net'],
            'A autoconfig.example.org' => ['192.0.2.25'],
        ]);

        $this->assertSame(['mx' => 'ok', 'spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'ok', 'autodiscover' => 'ok', 'autoconfig' => 'ok'], array_column($rows, 'status', 'key'));
        $this->assertTrue(MailDns::allNeededOk(array_values($rows)));
        $this->assertSame('10 mail.example.net', $rows['mx']['expected']);
    }

    public function test_nothing_set_fails_the_needed_and_warns_the_advised()
    {
        $rows = $this->check([]);

        $this->assertSame(['mx' => 'fail', 'spf' => 'fail', 'dkim' => 'fail', 'dmarc' => 'warn', 'autodiscover' => 'warn', 'autoconfig' => 'warn'], array_column($rows, 'status', 'key'));
        $this->assertSame(self::DKIM, $rows['dkim']['expected']);
        $this->assertFalse(MailDns::allNeededOk(array_values($rows)));
    }

    public function test_spf_through_an_include_a_server_it_does_not_name_or_two_records()
    {
        $this->assertSame('ok', $this->check(['MX example.org' => ['elsewhere.test'], 'TXT example.org' => ['v=spf1 include:spf.example.org ~all'],
            'TXT spf.example.org' => ['v=spf1 ip4:192.0.2.25 -all']])['spf']['status']);
        $this->assertSame('warn', $this->check(['MX example.org' => ['elsewhere.test'], 'TXT example.org' => ['v=spf1 mx ~all']])['spf']['status'],
            'mx, but the MX is not ours');
        $this->assertSame('fail', $this->check(['TXT example.org' => ['v=spf1 mx ~all', 'v=spf1 a ~all']])['spf']['status']);
    }

    public function test_another_dkim_key_another_mx_too_and_no_dkim_made_yet()
    {
        $rows = $this->check(['MX example.org' => ['mail.example.net', 'backup.example.org'], 'TXT dkim._domainkey.example.org' => ['v=DKIM1;p=OTHER']]);
        $this->assertSame(['warn', 'warn'], [$rows['mx']['status'], $rows['dkim']['status']]);

        $none = $this->check(['TXT dkim._domainkey.example.org' => ['v=DKIM1;p=']], 'example.org', null);
        $this->assertSame(['fail', 'dkim_none'], [$none['dkim']['status'], $none['dkim']['note']], 'no key at the server: never OK');
    }

    public function test_a_resolver_that_throws_is_no_record()
    {
        $rows = (new MailDns(function () {
            throw new \RuntimeException('timeout');
        }, 'mail.example.net', [], null, self::DKIM))->check('Example.org.');

        $this->assertSame('example.org', $rows[0]['name']);
        $this->assertSame('fail', $rows[0]['status']);
    }

    public function test_spf_redirect_cidr_a_and_an_includes_own_mx()
    {
        $spf = fn (array $dns) => $this->check($dns + ['MX example.org' => ['elsewhere.test']])['spf']['status'];

        $this->assertSame('ok', $spf(['TXT example.org' => ['v=spf1 redirect=_spf.example.org'], 'TXT _spf.example.org' => ['v=spf1 ip4:192.0.2.0/24 -all']]));
        $this->assertSame('ok', $spf(['TXT example.org' => ['v=spf1 a ~all'], 'A example.org' => ['192.0.2.25']]));
        $this->assertSame('ok', $spf(['TXT example.org' => ['v=spf1 a:mail.example.net ~all'], 'A mail.example.net' => ['192.0.2.25']]));
        $this->assertSame('warn', $spf(['TXT example.org' => ['v=spf1 -all mx'], 'MX example.org' => ['mail.example.net']]), 'first match wins: -all');
        $this->assertSame('warn', $spf(['TXT example.org' => ['v=spf1 -ip4:192.0.2.25 ip4:192.0.2.0/24 -all']]), 'our IP refused first');
        $this->assertSame('warn', $spf(['TXT example.org' => ['v=spf1 ~all redirect=_spf.example.org'], 'TXT _spf.example.org' => ['v=spf1 ip4:192.0.2.25 -all']]),
            'a redirect with all is ignored');
        $this->assertSame('warn', $spf(['TXT example.org' => ['v=spf1 ip4:198.51.100.0/24 -all']]));
        $this->assertSame('warn', $spf(['TXT example.org' => ['v=spf1 include:other.test -all'], 'TXT other.test' => ['v=spf1 mx -all']]),
            'mx in an include is the included domain\'s');
    }

    public function test_a_domain_can_never_make_it_ask_without_end()
    {
        $lookups = 0;
        $dns = ['TXT example.org' => ['v=spf1 mx ' . implode(' ', array_map(fn ($i) => "include:i{$i}.test", range(1, 60))) . ' -all']];
        foreach (range(1, 60) as $i) {
            $dns["TXT i{$i}.test"] = ['v=spf1 mx a -all'];
        }
        (new MailDns(function ($name, $type) use (&$lookups, $dns) {
            $lookups++;

            return $dns["{$type} {$name}"] ?? [];
        }, 'mail.example.net', ['192.0.2.25'], 'dkim', self::DKIM))->check('example.org');

        $this->assertLessThan(45, $lookups);

        $terms = implode(' ', array_map(fn ($i) => "a:a{$i}.test mx:m{$i}.test", range(1, 200)));
        $spf = new MailDns(function ($name, $type) use (&$lookups, $terms) {
            $lookups++;

            return $type === 'TXT' && $name === 'example.org' ? ["v=spf1 {$terms} -all"] : [];
        }, 'mail.example.net', ['192.0.2.25'], 'dkim', self::DKIM);
        $lookups = 0;
        $spf->check('example.org');
        $this->assertLessThan(25, $lookups, 'a: and mx: terms spend the 10 too');
    }

    /** Round 3 of #494: dual CIDR, and what a receiver takes for a permerror is never a ✓. */
    public function test_dual_cidr_and_a_permerror_is_never_ok()
    {
        $spf = fn (array $dns) => $this->check($dns + ['MX example.org' => ['mail.example.net']])['spf'];

        $this->assertSame('ok', $spf(['TXT example.org' => ['v=spf1 mx//64 -all']])['status']);
        $this->assertSame('ok', $spf(['TXT example.org' => ['v=spf1 a:mail.example.net//64 -all'], 'A mail.example.net' => ['192.0.2.25']])['status']);

        $chain = ['TXT example.org' => ['v=spf1 include:i1.test ip4:192.0.2.25 -all']];
        foreach (range(1, 12) as $i) {
            $chain["TXT i{$i}.test"] = ['v=spf1 include:i' . ($i + 1) . '.test -all'];
        }
        $this->assertSame(['fail', 'spf_error'], [$spf($chain)['status'], $spf($chain)['note']], 'past the 10 inside an include');
        $this->assertSame('fail', $spf(['TXT example.org' => ['v=spf1 include:nospf.test mx -all']])['status']);
        $this->assertSame('fail', $spf(['TXT example.org' => ['v=spf1 ip4:192.0.2.25/33 mx -all']])['status']);
    }
}

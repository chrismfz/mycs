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

        $this->assertArrayNotHasKey('dkim', $this->check([], dkim: null));
    }

    public function test_a_resolver_that_throws_is_no_record()
    {
        $rows = (new MailDns(function () {
            throw new \RuntimeException('timeout');
        }, 'mail.example.net', [], null, self::DKIM))->check('Example.org.');

        $this->assertSame('example.org', $rows[0]['name']);
        $this->assertSame('fail', $rows[0]['status']);
    }
}

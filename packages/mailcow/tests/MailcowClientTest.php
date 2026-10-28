<?php

namespace Nixpal\Mailcow\Tests;

use Nixpal\Mailcow\DomainLimits;
use Nixpal\Mailcow\MailcowClient;
use Nixpal\Mailcow\MailcowException;
use PHPUnit\Framework\TestCase;

final class MailcowClientTest extends TestCase
{
    private function client(FakeTransport $http, &$log = null)
    {
        $log = [];

        return new MailcowClient($http, 'mail.example.net', 'APIKEY', null, function () use (&$log) {
            $log[] = func_get_args();
        });
    }

    private static function ok()
    {
        return [['type' => 'success', 'msg' => ['ok']]];
    }

    public function test_base_url_accepts_host_ip_or_url()
    {
        $this->assertSame('https://mail.example.net', MailcowClient::baseUrl('mail.example.net'));
        $this->assertSame('https://mail.example.net', MailcowClient::baseUrl(' https://mail.example.net/ '));
        $this->assertSame('http://192.0.2.10:8080', MailcowClient::baseUrl('http://192.0.2.10:8080'));
        $this->expectException(MailcowException::class);
        MailcowClient::baseUrl('');
    }

    public function test_sends_the_api_key_header_and_json()
    {
        $http = (new FakeTransport)->queue(200, self::ok());
        $limits = DomainLimits::fromMeta(['aliases' => 50, 'mailboxes' => 5, 'defquota' => 512, 'maxquota' => 2048, 'quota' => 10240, 'rl_value' => 200, 'rl_frame' => 'h']);
        $this->client($http)->addDomain('example.org', $limits);

        $request = $http->last();
        $this->assertSame('https://mail.example.net/api/v1/add/domain', $request['url']);
        $this->assertSame('APIKEY', $request['headers']['X-API-Key']);
        $body = $http->lastJson();
        $this->assertSame('example.org', $body['domain']);
        $this->assertSame(['50', '5', '512', '2048', '10240', '200', 'h'], [
            $body['aliases'], $body['mailboxes'], $body['defquota'], $body['maxquota'], $body['quota'], $body['rl_value'], $body['rl_frame'],
        ]);
        $this->assertSame('1', $body['active']);
        $this->assertSame('1', $body['restart_sogo']);
    }

    public function test_mailcow_errors_inside_http200_throw()
    {
        $http = (new FakeTransport)->queue(200, [['type' => 'danger', 'msg' => ['domain_exists', 'example.org']]]);

        $this->expectException(MailcowException::class);
        $this->expectExceptionMessage('domain_exists example.org');
        $this->client($http)->removeDomain('example.org');
    }

    public function test_http_errors_throw_without_the_key()
    {
        $http = (new FakeTransport)->queue(401, 'Unauthorized');

        try {
            $this->client($http)->testConnection();
            $this->fail('expected exception');
        } catch (MailcowException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('APIKEY', $e->getMessage());
        }
    }

    public function test_the_log_never_sees_passwords_or_the_key()
    {
        $http = (new FakeTransport)->queue(200, self::ok())->queue(200, self::ok());
        $client = $this->client($http, $log);
        $client->addDomainAdmin('example.org', 'exampl', 'S3cr3t"pw');
        $client->changeDomainAdminPassword(['example.org'], 'exampl', 'N3w-pw');

        $this->assertStringNotContainsString('S3cr3t', json_encode($log));
        $this->assertStringNotContainsString('N3w-pw', json_encode($log));
        $this->assertStringNotContainsString('APIKEY', json_encode($log));
        $this->assertStringContainsString('"password":"***"', $log[0][2]);
    }

    public function test_domain_admin_domains_come_from_selected_domains()
    {
        $http = (new FakeTransport)->queue(200, [
            ['username' => 'other', 'selected_domains' => ['x.org']],
            ['username' => 'exampl', 'selected_domains' => 'example.org, Second.org  third.org'],
        ]);

        $this->assertSame(['example.org', 'second.org', 'third.org'], $this->client($http)->getDomainAdminDomains('exampl'));
    }

    public function test_unknown_domain_admin_has_no_domains()
    {
        $http = (new FakeTransport)->queue(200, []);

        $this->assertSame([], $this->client($http)->getDomainAdminDomains('nobody'));
        $this->assertSame([], $this->client(new FakeTransport)->getDomainAdminDomains(''));
    }

    public function test_ensure_dkim_keeps_an_existing_key()
    {
        $http = (new FakeTransport)->queue(200, ['dkim_txt' => 'v=DKIM1;k=rsa;p=AAA']);
        $this->client($http)->ensureDkim('example.org');

        $this->assertCount(1, $http->requests);
    }

    public function test_ensure_dkim_creates_a_missing_key()
    {
        $http = (new FakeTransport)->queue(200, [])->queue(200, self::ok());
        $this->client($http)->ensureDkim('example.org', 'mc', 4096);

        $this->assertSame('https://mail.example.net/api/v1/add/dkim', $http->last()['url']);
        $this->assertSame(['domains' => 'example.org', 'dkim_selector' => 'mc', 'key_size' => '4096'], $http->lastJson());
    }

    public function test_overview_fetches_mailboxes_and_aliases_once_for_all_domains()
    {
        $http = (new FakeTransport)
            ->queue(200, [['username' => 'a@example.org'], ['username' => 'b@second.org'], ['username' => 'c@notexample.org']])
            ->queue(200, [['address' => 'info@example.org', 'id' => 1], ['address' => 'x@second.org', 'id' => 2]])
            ->queue(200, [['domain_name' => 'example.org', 'bytes_total' => 10]])
            ->queue(200, [['domain_name' => 'second.org', 'bytes_total' => 20]]);

        $overview = $this->client($http)->overview(['example.org', 'second.org']);

        $this->assertCount(4, $http->requests);
        $this->assertSame(['a@example.org'], array_column($overview[0]['mailboxes'], 'username'));
        $this->assertSame(['info@example.org'], array_column($overview[0]['aliases'], 'address'));
        $this->assertSame(['b@second.org'], array_column($overview[1]['mailboxes'], 'username'));
        $this->assertSame(20, $overview[1]['domain']['bytes_total']);
    }

    public function test_delete_all_removes_only_the_domains_mailboxes_and_aliases()
    {
        $http = (new FakeTransport)
            ->queue(200, [['username' => 'a@example.org'], ['username' => 'b@example.org.evil'], ['username' => 'c@sub.example.org']])
            ->queue(200, self::ok())
            ->queue(200, [['address' => 'x@example.org', 'id' => 7], ['address' => 'y@other.org', 'id' => 8]])
            ->queue(200, self::ok());
        $client = $this->client($http);

        $client->removeDomainMailboxes('example.org');
        $this->assertSame(['a@example.org'], $http->lastJson());
        $client->removeDomainAliases('example.org');
        $this->assertSame([7], $http->lastJson());
    }

    public function test_sso_token_is_issued_for_the_admin()
    {
        $http = (new FakeTransport)->queue(200, [['type' => 'success', 'msg' => 'abcdef0123456789abcdef']]);

        $this->assertSame('abcdef0123456789abcdef', $this->client($http)->issueSsoToken('exampl'));
        $this->assertSame(['username' => 'exampl'], $http->lastJson());
        $this->assertStringEndsWith('/api/v1/add/sso/domain-admin', $http->last()['url']);
    }

    public function test_missing_sso_token_throws()
    {
        $http = (new FakeTransport)->queue(200, [['type' => 'success', 'msg' => 'ok']]);

        $this->expectException(MailcowException::class);
        $this->client($http)->issueSsoToken('exampl');
    }

    public function test_lists_the_domains_and_mailboxes_and_never_takes_an_error_for_an_empty_server()
    {
        $http = (new FakeTransport)
            ->queue(200, [['domain_name' => 'example.org', 'mboxes_in_domain' => 2]])
            ->queue(200, [['username' => 'a@example.org', 'quota_used' => 10]])
            ->queue(200, [])
            ->queue(200, ['type' => 'error', 'msg' => 'access denied']);
        $client = $this->client($http);

        $this->assertSame('example.org', $client->listDomains()[0]['domain_name']);
        $this->assertSame('https://mail.example.net/api/v1/get/domain/all', $http->requests[0]['url']);
        $this->assertSame('a@example.org', $client->listMailboxes()[0]['username']);
        $this->assertSame([], $client->listDomains());
        $this->expectException(MailcowException::class);
        $client->listMailboxes();
    }
}

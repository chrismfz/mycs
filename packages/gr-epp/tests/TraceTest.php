<?php

namespace MyCS\GrEpp\Tests;

use MyCS\GrEpp\Config;
use MyCS\GrEpp\Frame;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Http\TracingTransport;
use MyCS\GrEpp\Session;
use MyCS\GrEpp\Trace;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The debug trace: every exchange told with its command, clTRID, svTRID and code; the frames shown without a secret
 * (the login's password and new password, an EPP code in a request or an answer); a tap that fails never fails a command.
 */
final class TraceTest extends TestCase
{
    private const PASSWORD = 'Sec&ret]]>pw';

    public function test_a_session_traced_tells_each_exchange_and_never_a_secret(): void
    {
        $fake = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=SECRETCOOKIE'])->fixture('04-login-response.xml')
            ->fixture('38-domain-create-response.xml');
        $told = [];
        $transport = new TracingTransport($fake, function (array $summary, string $request, string $response, float $ms) use (&$told) {
            $told[] = [$summary, $request, $response];
        });
        $session = new Session(new Config('myip1', self::PASSWORD), $transport);

        $create = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><create><domain:create xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            . '<domain:name>example.gr</domain:name><domain:authInfo><domain:pw>Code-123</domain:pw></domain:authInfo></domain:create></create><clTRID>ABC-12345</clTRID></command></epp>';
        $session->command($create);

        $this->assertSame(['hello', 'login', 'domain:create'], array_map(fn ($t) => $t[0]['command'], $told));
        $this->assertSame(['clTRID' => 'ABC-12345', 'code' => 1001, 'http' => 200], array_intersect_key($told[2][0], ['clTRID' => 1, 'code' => 1, 'http' => 1]));
        $this->assertNotNull($told[2][0]['svTRID']);
        $all = json_encode($told);
        foreach (['Sec&amp;ret', 'Sec&ret', 'ret]]', 'Code-123', 'SECRETCOOKIE'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $all);
        }
        $this->assertStringContainsString('<domain:pw>***</domain:pw>', $told[2][1]);
        $this->assertStringContainsString('<domain:name>example.gr</domain:name>', $told[2][1], 'the rest kept');
    }

    public function test_redact_hides_every_pw_and_never_shows_what_does_not_parse(): void
    {
        $login = Frame::login(new Config('myip1', self::PASSWORD), 't-1', 'New]]>Pass&1');
        $shown = Trace::redact($login);
        $this->assertStringNotContainsString('Sec', $shown);
        $this->assertStringNotContainsString('New', $shown);
        $this->assertSame(2, substr_count($shown, '***'));

        $info = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:authInfo><domain:pw><![CDATA[Code</domain:pw>-9]]></domain:pw></domain:authInfo></domain:infData></resData>'
            . '<extension><extdomain:pw xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.2">Other-1</extdomain:pw></extension></response></epp>';
        $shown = Trace::redact($info);
        $this->assertStringNotContainsString('Code', $shown);
        $this->assertStringNotContainsString('-9', $shown);
        $this->assertStringNotContainsString('Other-1', $shown);

        $this->assertSame('(not XML: 18 bytes, not shown)', Trace::redact('<pw>leak</pw> oops'));
        $this->assertSame('', Trace::redact(''));
    }

    public function test_a_tap_that_fails_never_fails_the_command_and_no_answer_is_told(): void
    {
        $fake = (new FakeTransport)->queue(new HttpResponse(0, '', [], 'timeout'));
        $told = [];
        $transport = new TracingTransport($fake, function (array $summary, string $request, string $response) use (&$told) {
            $told[] = [$summary, $response];
        });
        $this->assertSame(0, $transport->post('https://x', '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><hello/></epp>', [])->status);
        $this->assertSame(['hello', null, 0], [$told[0][0]['command'], $told[0][0]['code'], $told[0][0]['http']]);
        $this->assertStringContainsString('no answer: timeout', $told[0][1]);

        $failing = new TracingTransport((new FakeTransport)->fixture('02-hello-response.xml'), fn () => throw new RuntimeException('disk full'));
        $this->assertSame(200, $failing->post('https://x', '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><hello/></epp>', [])->status);
    }
}

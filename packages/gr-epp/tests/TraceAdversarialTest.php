<?php

namespace MyCS\GrEpp\Tests;

use MyCS\GrEpp\Command\DomainRenew;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Http\TracingTransport;
use MyCS\GrEpp\Trace;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of #193 (the debug trace): secrets that escape Trace::redact, and the parser's safety against
 * frames built to leak or to hurt (DOCTYPE, external and recursive entities, CDATA, prefixes, huge frames).
 */
final class TraceAdversarialTest extends TestCase
{
    private const EPP = 'xmlns="urn:ietf:params:xml:ns:epp-1.0"';

    /**
     * ADV-1: domain:renew of another registrar's name (the guide's example 53) carries the name's authorisation code in
     * <extdomain:currentPW>: not «pw» nor «newPW», so it reaches the debug log as it is.
     */
    public function test_adv1_the_current_pw_of_a_renewal_of_another_registrars_name_is_hidden(): void
    {
        $request = DomainRenew::request('example.gr', '2026-11-01', 2, 'mycs-1-abc', 'c60_mcs1', 'Auth-Code-77');
        $this->assertStringContainsString('Auth-Code-77', $request, 'the frame carries it');

        $this->assertStringNotContainsString('Auth-Code-77', Trace::redact($request));

        $guide = (string) file_get_contents(__DIR__ . '/fixtures/v4.3/53-domain-renew-expired-by-different-registrar-request.xml');
        $this->assertStringNotContainsString('domain-auth-code', Trace::redact($guide), 'the guide\'s own example');
    }

    /**
     * ADV-1 through the transport (what the tap, so the debug log, gets).
     */
    public function test_adv1_the_tap_never_gets_the_current_pw(): void
    {
        $told = [];
        $transport = new TracingTransport((new FakeTransport)->result(1000), function (array $summary, \Closure $frames) use (&$told) {
            [$request, $response] = $frames();
            $told[] = $summary + ['request' => $request, 'response' => $response];
        });
        $transport->post('https://x', DomainRenew::request('example.gr', '2026-11-01', 2, 'mycs-1-abc', 'c60_mcs1', 'Auth-Code-77'), []);

        $this->assertStringNotContainsString('Auth-Code-77', (string) json_encode($told));
    }

    /**
     * ADV-2 (low; depends on the registry): an error answer that names the bad value in its text (a <reason>, a <msg>,
     * a Java schema message «Value 'x' is not facet-valid … pwType») shows the EPP code sent: redact only knows elements.
     * The values hidden in the request are not hidden in its answer.
     */
    public function test_adv2_an_answer_that_echoes_the_code_sent_in_its_text_never_shows_it(): void
    {
        $request = '<epp ' . self::EPP . '><command><transfer op="request"><domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example.gr</domain:name>'
            . '<domain:authInfo><domain:pw>Wrong-Code-12345-too-long</domain:pw></domain:authInfo></domain:transfer></transfer><clTRID>mycs-2</clTRID></command></epp>';
        $answer = '<epp ' . self::EPP . '><response><result code="2001"><msg>Command syntax error</msg><extValue><value><domain:pw xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">Wrong-Code-12345-too-long</domain:pw></value>'
            . "<reason>cvc-maxLength-valid: Value 'Wrong-Code-12345-too-long' with length = '25' is not facet-valid with respect to maxLength '16' for type 'pwType'.</reason></extValue></result>"
            . '<trID><clTRID>mycs-2</clTRID><svTRID>s-1</svTRID></trID></response></epp>';
        $told = [];
        $transport = new TracingTransport((new FakeTransport)->queue(new HttpResponse(200, $answer)), function (array $summary, \Closure $frames) use (&$told) {
            $told[] = [$summary, ...$frames()];
        });
        $transport->post('https://x', $request, []);

        $this->assertSame(2001, $told[0][0]['code']);
        $this->assertStringNotContainsString('Wrong-Code', (string) json_encode($told));
    }

    // Regression (passing): the parser used for redaction and summaries.

    public function test_an_external_entity_is_never_read_and_never_shown(): void
    {
        $secretFile = tempnam(sys_get_temp_dir(), 'xxe');
        file_put_contents((string) $secretFile, 'LOCAL-FILE-SECRET');
        try {
            foreach (['<!DOCTYPE epp [<!ENTITY s SYSTEM "file://' . $secretFile . '">]><epp ' . self::EPP . '><command><x>&s;</x><clTRID>&s;</clTRID></command></epp>',
                '<!DOCTYPE epp SYSTEM "file://' . $secretFile . '"><epp ' . self::EPP . '><command/></epp>',
                '<!DOCTYPE epp [<!ENTITY % p SYSTEM "file://' . $secretFile . '"> %p;]><epp ' . self::EPP . '><command/></epp>'] as $xml) {
                $this->assertStringNotContainsString('LOCAL-FILE-SECRET', Trace::redact($xml));
                $this->assertStringNotContainsString('LOCAL-FILE-SECRET', (string) json_encode(Trace::summary($xml, 200, $xml)));
            }
        } finally {
            @unlink((string) $secretFile);
        }
    }

    public function test_an_internal_entity_never_carries_a_secret_out_and_a_billion_laughs_never_explodes(): void
    {
        $xml = '<!DOCTYPE epp [<!ENTITY s "DTD-SECRET"><!ENTITY e "<pw>ELEMENT-SECRET</pw>">]><epp ' . self::EPP . '><command><pw>&s;</pw><x>&s;</x>&e;</command></epp>';
        $shown = Trace::redact($xml);
        $this->assertStringNotContainsString('DTD-SECRET', $shown);
        $this->assertStringNotContainsString('ELEMENT-SECRET', $shown);

        $lol = '<!DOCTYPE epp [<!ENTITY a "aaaaaaaaaa">';
        $previous = 'a';
        foreach (range('b', 'j') as $name) {
            $lol .= "<!ENTITY {$name} \"" . str_repeat("&{$previous};", 10) . '">';
            $previous = $name;
        }
        $lol .= ']><epp ' . self::EPP . '><command><pw>&j;</pw><clTRID>&j;</clTRID></command><response><trID><svTRID>&j;</svTRID></trID></response></epp>';
        $before = memory_get_peak_usage();
        $started = microtime(true);
        $shown = Trace::redact($lol);
        $summary = Trace::summary($lol, 200, $lol);
        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertLessThan(50_000_000, memory_get_peak_usage() - $before);
        $this->assertLessThan(10_000, strlen($shown . json_encode($summary)));
    }

    public function test_every_form_of_a_pw_is_hidden_and_what_does_not_parse_is_never_shown(): void
    {
        $frames = [
            '<epp ' . self::EPP . '><command><login><clID>x</clID><pw><![CDATA[P1-secret]]></pw><newPW>P2-secret</newPW></login></command></epp>',
            '<e:epp xmlns:e="urn:ietf:params:xml:ns:epp-1.0"><e:command><e:login><e:pw>P3-secret</e:pw></e:login></e:command></e:epp>',
            '<epp ' . self::EPP . '><command><info><d:info xmlns:d="urn:ietf:params:xml:ns:domain-1.0"><d:authInfo><d:pw roid="R-1">P4&#45;secret<!-- c --><![CDATA[x]]></d:pw></d:authInfo></d:info></info></command></epp>',
            '<epp ' . self::EPP . '><response><resData><contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"><contact:authInfo><contact:pw>P5-secret</contact:pw></contact:authInfo></contact:infData></resData></response></epp>',
            '<epp ' . self::EPP . '><command><delete><domain:delete xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"/></delete><extension><extdomain:delete xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.2"><extdomain:pw>P6-secret</extdomain:pw></extdomain:delete></extension></command></epp>',
            '<?xml version="1.0" encoding="ISO-8859-7"?><epp ' . self::EPP . '><command><login><pw>P7-secret</pw></login></command></epp>',
        ];
        foreach ($frames as $i => $frame) {
            $shown = Trace::redact($frame);
            $this->assertDoesNotMatchRegularExpression('/P\d(-|&#45;)secret/', $shown, "frame {$i}: {$shown}");
            $this->assertStringContainsString('***', $shown, "frame {$i}");
        }

        foreach (['<epp><pw>P8-secret</pw>', '<epp><pw>P8-secret</pw></epp><epp/>', "<epp>\0<pw>P8-secret</pw></epp>", 'HTTP/1.1 500 <pw>P8-secret</pw>',
            '<epp><pw>' . str_repeat('A', 11_000_000) . 'P8-secret</pw></epp>'] as $broken) {
            $this->assertStringNotContainsString('P8-secret', Trace::redact($broken));
        }
    }

    public function test_a_summary_never_carries_a_pw_and_the_headers_never_reach_the_tap(): void
    {
        $login = '<epp ' . self::EPP . '><command><login><clID>myip1</clID><pw>Login-Secret1</pw><newPW>Login-Secret2</newPW></login><clTRID>mycs-9</clTRID></command></epp>';
        $answer = '<epp ' . self::EPP . '><response><result code="1000"><msg>ok</msg></result><trID><clTRID>mycs-9</clTRID><svTRID>s-9</svTRID></trID></response></epp>';
        $summary = Trace::summary($login, 200, $answer);
        $this->assertSame(['command' => 'login', 'clTRID' => 'mycs-9', 'svTRID' => 's-9', 'code' => 1000, 'http' => 200], $summary);

        $told = [];
        $transport = new TracingTransport((new FakeTransport)->queue(new HttpResponse(200, $answer, ['JSESSIONID=COOKIE-SECRET'])),
            function (array $summary, \Closure $frames) use (&$told) {
                $told[] = [$summary, ...$frames()];
            });
        $transport->post('https://x', $login, ['Cookie' => 'JSESSIONID=COOKIE-SECRET']);
        $this->assertStringNotContainsString('COOKIE-SECRET', (string) json_encode($told));
        $this->assertStringNotContainsString('Login-Secret', (string) json_encode($told));
    }

    public function test_a_tap_that_throws_an_error_or_is_slow_still_returns_the_registrys_answer_once(): void
    {
        $fake = (new FakeTransport)->result(1000);
        $transport = new TracingTransport($fake, function (): void {
            throw new \TypeError('a bug in the tap');
        });
        $this->assertSame(200, $transport->post('https://x', '<epp ' . self::EPP . '><hello/></epp>', [])->status);
        $this->assertCount(1, $fake->sent, 'sent once: a tap is never a retry');
    }
}

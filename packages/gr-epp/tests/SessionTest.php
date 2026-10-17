<?php

namespace MyCS\GrEpp\Tests;

use DOMDocument;
use InvalidArgumentException;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Frame;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\NotSent;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Refused;
use MyCS\GrEpp\Response;
use MyCS\GrEpp\Session;
use PHPUnit\Framework\TestCase;

/**
 * G1: the session at the .gr registry, against the registry's own examples (guide v4.3): hello for the cookie, login
 * (no svcExtension, the password in CDATA, valid to epp-1.0.xsd), commands with the cookie, a fresh login after the
 * idle limit and once on 2002, logout; what a failure means (refused, maybe done, never sent); the password never shown.
 */
final class SessionTest extends TestCase
{
    private const PASSWORD = 'Sec&ret]]>pw';

    private const CHECK = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><check><domain:check xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>nac.gr</domain:name></domain:check></check><clTRID>t-1</clTRID></command></epp>';

    private float $now = 1000.0;

    private function session(FakeTransport $transport, int $maxIdle = 300): Session
    {
        return new Session(new Config('myip1', self::PASSWORD, maxIdle: $maxIdle), $transport, fn () => $this->now, fn () => 'mycs-test-1');
    }

    private function loggedIn(): FakeTransport
    {
        return (new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=ABC123; Path=/epp; Secure; HttpOnly', 'other=x'])
            ->fixture('04-login-response.xml');
    }

    public function test_the_first_command_says_hello_logs_in_and_carries_the_cookie(): void
    {
        $transport = $this->loggedIn()->result(1000);

        $response = $this->session($transport)->command(self::CHECK);

        $this->assertSame([1000, 's-1000'], [$response->code, $response->svTRID]);
        $this->assertSame(['hello', 'login', 'check'], $transport->kinds());
        $this->assertSame(Config::PRODUCTION, $transport->sent[0]['url']);
        $this->assertSame('application/epp+xml;charset=UTF-8', $transport->sent[0]['headers']['Content-Type'], 'the guide v4.3, changes item 8');
        $this->assertArrayNotHasKey('Cookie', $transport->sent[0]['headers']);
        $this->assertSame('JSESSIONID=ABC123; other=x', $transport->sent[1]['headers']['Cookie'], 'each cookie by name, the part before the first «;»');
        $this->assertSame('JSESSIONID=ABC123; other=x', $transport->sent[2]['headers']['Cookie']);
    }

    public function test_the_login_is_the_registrys_valid_to_its_schema_without_svc_extensions(): void
    {
        $xml = Frame::login(new Config('myip1', 'abc123', lang: 'el'), 'mycs-test-1');
        $document = new DOMDocument;
        $document->loadXML($xml);

        $this->assertTrue($document->schemaValidate(dirname(__DIR__) . '/resources/xsd/epp-1.0.xsd'));
        $this->assertStringContainsString('<pw><![CDATA[abc123]]></pw>', $xml);
        $this->assertStringNotContainsString('svcExtension', $xml);
        $this->assertSame(Frame::OBJECTS, array_map(fn ($n) => $n->textContent, iterator_to_array($document->getElementsByTagName('objURI'))));
        $this->assertSame('el', $document->getElementsByTagName('lang')->item(0)?->textContent);

        // A password change in the same login (the guide's example 05); a «]]>» in it stays the same value.
        $change = new DOMDocument;
        $change->loadXML(Frame::login(new Config('myip1', self::PASSWORD), 'mycs-test-1', 'New]]>pw1'));
        $this->assertTrue($change->schemaValidate(dirname(__DIR__) . '/resources/xsd/epp-1.0.xsd'));
        $this->assertSame([self::PASSWORD, 'New]]>pw1'], [$change->getElementsByTagName('pw')->item(0)?->textContent, $change->getElementsByTagName('newPW')->item(0)?->textContent]);
    }

    public function test_hello_and_logout_are_valid_and_logout_ends_the_session(): void
    {
        foreach ([Frame::hello(), Frame::logout('mycs-test-1')] as $xml) {
            $document = new DOMDocument;
            $document->loadXML($xml);
            $this->assertTrue($document->schemaValidate(dirname(__DIR__) . '/resources/xsd/epp-1.0.xsd'));
        }
        $transport = $this->loggedIn()->fixture('08-logout-response.xml');
        $session = $this->session($transport);
        $session->login();

        $session->logout();

        $this->assertFalse($session->loggedIn());
        $this->assertSame(['hello', 'login', 'logout'], $transport->kinds());
        $session->logout(); // nothing to end: nothing sent
        $this->assertCount(3, $transport->sent);
    }

    public function test_the_registrys_answers_are_read(): void
    {
        $greeting = Response::parse((string) file_get_contents(__DIR__ . '/fixtures/v4.3/02-hello-response.xml'));
        $login = Response::parse((string) file_get_contents(__DIR__ . '/fixtures/v4.3/04-login-response.xml'));
        $logout = Response::parse((string) file_get_contents(__DIR__ . '/fixtures/v4.3/08-logout-response.xml'));

        $this->assertTrue($greeting->greeting);
        $this->assertSame([1000, 'Η εντολή ολοκληρώθηκε επιτυχώς', 'Καλώς ήλθατε στο Μητρώο.', 'ABC:ics-forth:1079595109464', '20110523-101636996-783-gr'],
            [$login->code, $login->message, $login->comment(), $login->clTRID, $login->svTRID]);
        $this->assertTrue($logout->isSuccess());
        $this->assertSame(1500, $logout->code);
    }

    public function test_1001_is_success_and_a_2xxx_is_a_refusal_with_the_registrys_words(): void
    {
        $this->assertSame(1001, $this->session($this->loggedIn()->result(1001))->command(self::CHECK)->code);

        try {
            $this->session($this->loggedIn()->result(2303, 'Το αντικείμενο δεν υπάρχει'))->command(self::CHECK);
            $this->fail('refused');
        } catch (Refused $e) {
            $this->assertSame([2303, 'Το αντικείμενο δεν υπάρχει', 's-2303'], [$e->resultCode, $e->registryMessage, $e->svTRID]);
        }
    }

    public function test_what_may_have_been_done_is_outcome_unknown(): void
    {
        $cases = [
            'a timeout after sending' => new HttpResponse(0, '', [], 'Operation timed out', neverSent: false),
            'HTTP 502' => new HttpResponse(502, 'Bad gateway'),
            'not XML' => new HttpResponse(200, '<html>maintenance</html>'),
            'an entity' => new HttpResponse(200, '<?xml version="1.0"?><!DOCTYPE epp [<!ENTITY x SYSTEM "file:///etc/passwd">]><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>&x;</msg></result></response></epp>'),
        ];
        foreach ($cases as $case => $answer) {
            try {
                $this->session($this->loggedIn()->queue($answer))->command(self::CHECK);
                $this->fail($case);
            } catch (OutcomeUnknown) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([2400, 2500, 2502] as $code) {
            try {
                $this->session($this->loggedIn()->result($code))->command(self::CHECK);
                $this->fail("{$code}");
            } catch (OutcomeUnknown $e) {
                $this->assertSame($code, $e->resultCode);
            }
        }
    }

    public function test_what_never_reached_the_registry_is_not_sent(): void
    {
        // No connection at all.
        $transport = (new FakeTransport)->queue(new HttpResponse(0, '', [], 'Could not resolve host', neverSent: true));
        try {
            $this->session($transport)->command(self::CHECK);
            $this->fail('not sent');
        } catch (NotSent) {
            $this->assertSame(['hello'], $transport->kinds());
        }

        // The login refused: the command is never sent; the registry's words kept.
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->result(2200, 'Λάθος ταυτοποίηση χρήστη');
        try {
            $this->session($transport)->command(self::CHECK);
            $this->fail('not sent');
        } catch (NotSent $e) {
            $this->assertStringContainsString('Λάθος ταυτοποίηση χρήστη', $e->getMessage());
            $this->assertSame(['hello', 'login'], $transport->kinds());
        }

        // A timeout of the command itself after a good login is not «not sent».
        $this->expectException(OutcomeUnknown::class);
        $this->session($this->loggedIn()->queue(new HttpResponse(0, '', [], 'timeout')))->command(self::CHECK);
    }

    public function test_a_session_gone_is_logged_in_again_once(): void
    {
        // 2002: the guide's «invalid session»; nothing done, so once more in a new session (logout, hello, login).
        $transport = $this->loggedIn()->result(2002, 'Λάθος χρήση εντολής')->fixture('08-logout-response.xml')
            ->fixture('02-hello-response.xml', ['JSESSIONID=NEW'])->fixture('04-login-response.xml')->result(1000);
        $this->assertSame(1000, $this->session($transport)->command(self::CHECK)->code);
        $this->assertSame(['hello', 'login', 'check', 'logout', 'hello', 'login', 'check'], $transport->kinds());
        $this->assertSame('JSESSIONID=NEW', $transport->sent[6]['headers']['Cookie']);

        // A 2002 again is the command's own (an empty update): refused, not a third try.
        $transport = $this->loggedIn()->result(2002)->fixture('08-logout-response.xml')
            ->fixture('02-hello-response.xml', ['JSESSIONID=NEW'])->fixture('04-login-response.xml')->result(2002);
        $this->expectException(Refused::class);
        $this->session($transport)->command(self::CHECK);
    }

    public function test_after_the_idle_limit_a_fresh_session(): void
    {
        // The registry ends a session idle for 15 minutes: past maxIdle a logout (best effort) and a new login.
        $transport = $this->loggedIn()->result(1000)->fixture('08-logout-response.xml')
            ->fixture('02-hello-response.xml', ['JSESSIONID=NEW'])->fixture('04-login-response.xml')->result(1000);
        $session = $this->session($transport, maxIdle: 300);
        $session->command(self::CHECK);

        $this->now += 301;
        $session->command(self::CHECK);

        $this->assertSame(['hello', 'login', 'check', 'logout', 'hello', 'login', 'check'], $transport->kinds());
        $this->assertSame('JSESSIONID=NEW', $transport->sent[6]['headers']['Cookie']);
    }

    public function test_the_password_is_never_shown(): void
    {
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->result(2200, 'denied');
        $session = $this->session($transport);
        try {
            $session->command(self::CHECK);
        } catch (NotSent $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage() . $e->getPrevious()?->getMessage() . $e->getTraceAsString());
        }
        $this->assertStringNotContainsString(self::PASSWORD, print_r($session, true));
        ob_start();
        var_dump(new Config('myip1', self::PASSWORD));
        $this->assertStringNotContainsString(self::PASSWORD, (string) ob_get_clean());

        try {
            new Config('myip1', 'short');
            $this->fail('too short');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('short', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        serialize(new Config('myip1', self::PASSWORD));
    }

    public function test_login_failures_mean_what_they_are(): void
    {
        // The registry busy (2502) is not a refusal of the account (review 3 of #184).
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->result(2502, 'busy');
        try {
            $this->session($transport)->login();
            $this->fail('not sent');
        } catch (NotSent $e) {
            $this->assertStringContainsString('2502', $e->getMessage());
        }

        // A password change whose answer is lost may have changed it (review 2 of #184).
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->queue(new HttpResponse(0, '', [], 'timeout'));
        try {
            $this->session($transport)->login('NewPass99');
            $this->fail('unknown');
        } catch (OutcomeUnknown) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        Frame::login(new Config('myip1', self::PASSWORD), 'mycs-test-1', 'short');
    }

    public function test_cookies_are_kept_by_name(): void
    {
        // A balancer's cookie never replaces the session's; Max-Age=0 deletes one (review 4 of #184).
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=ABC; Path=/', 'LB=node1'])
            ->queue(new HttpResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/v4.3/04-login-response.xml'), ['LB=node2', 'gone=; Max-Age=0']))
            ->queue(new HttpResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/v4.3/04-login-response.xml'), ['LB=x; Max-Age=0']))
            ->result(1000);
        $session = $this->session($transport);
        $session->login();
        $session->command(self::CHECK);

        $this->assertSame('JSESSIONID=ABC; LB=node2', $transport->sent[2]['headers']['Cookie']);
    }

    public function test_a_closed_session_or_another_answer_is_not_taken_for_the_commands(): void
    {
        // 25xx: the registry closed the session; the next command logs in again (review 7 of #184).
        $transport = $this->loggedIn()->result(2500)->fixture('02-hello-response.xml', ['JSESSIONID=NEW'])->fixture('04-login-response.xml')->result(1000);
        $session = $this->session($transport);
        try {
            $session->command(self::CHECK);
        } catch (OutcomeUnknown) {
            $this->assertFalse($session->loggedIn());
        }
        $session->command(self::CHECK);
        $this->assertSame(['hello', 'login', 'check', 'hello', 'login', 'check'], $transport->kinds());

        // An answer carrying another clTRID is not this command's (review 11 of #184).
        $this->expectException(OutcomeUnknown::class);
        $this->session($this->loggedIn()->result(1000))->command(self::CHECK, 't-1');
    }

    public function test_the_user_follows_the_schema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config('ab', self::PASSWORD); // eppcom clIDType: 3 to 16
    }

    public function test_only_https_endpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config('myip1', self::PASSWORD, endpoint: 'http://regepp.ics.forth.gr:700/epp/proxy');
    }
}

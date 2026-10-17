<?php

namespace MyCS\GrEpp\Tests;

use InvalidArgumentException;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\GrEppException;
use MyCS\GrEpp\Http\CurlTransport;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\NotSent;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Refused;
use MyCS\GrEpp\Response;
use MyCS\GrEpp\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Throwable;

/**
 * Adversarial review of PR #184 (G1). ADV-* tests fail on the branch as reviewed (a defect); REG-* tests pin
 * hypotheses that held (cheap regressions).
 */
final class SessionAdversarialTest extends TestCase
{
    private const PASSWORD = 'Adv3rs&ry]]>pw';

    private const RENEW = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><renew><domain:renew xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>nac.gr</domain:name><domain:curExpDate>2027-01-01</domain:curExpDate><domain:period unit="y">2</domain:period></domain:renew></renew><clTRID>t-1</clTRID></command></epp>';

    private float $now = 1000.0;

    private function config(int $maxIdle = 300): Config
    {
        return new Config('myip1', self::PASSWORD, maxIdle: $maxIdle);
    }

    private function session(FakeTransport $transport, int $maxIdle = 300): Session
    {
        return new Session($this->config($maxIdle), $transport, fn () => $this->now, fn () => 'mycs-adv-1');
    }

    private function loggedIn(?FakeTransport $transport = null): FakeTransport
    {
        return ($transport ?? new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=ABC123; Path=/epp; Secure; HttpOnly'])
            ->fixture('04-login-response.xml');
    }

    private function body(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>' . $inner
            . '<trID><clTRID>c</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    /** @return class-string<Throwable>|null */
    private function thrown(callable $call): ?string
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e::class;
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV: defects (fail on the branch as reviewed)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-1 (high). A login with <newPW> changes the account's password at the registry. If it was sent and no answer
     * came back (a timeout), the password may have changed; NotSent says «nothing done, safe to send again», and the
     * caller keeps (and stores) the old password: every later login is refused, the account is locked out.
     */
    public function test_adv1_a_password_change_login_that_timed_out_after_sending_is_outcome_unknown(): void
    {
        $transport = (new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=ABC123'])
            ->queue(new HttpResponse(0, '', [], 'Operation timed out after 60000 milliseconds', neverSent: false));

        $this->assertSame(OutcomeUnknown::class, $this->thrown(fn () => $this->session($transport)->login('N3wPassw0rd')));
    }

    /** ADV-1b (high). The same for a password-change login answered 2400/25xx (maybe done): not a plain refusal. */
    public function test_adv1b_a_password_change_login_answered_2400_is_outcome_unknown(): void
    {
        $transport = (new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=ABC123'])
            ->result(2400, 'Command failed');

        $this->assertSame(OutcomeUnknown::class, $this->thrown(fn () => $this->session($transport)->login('N3wPassw0rd')));
    }

    /**
     * ADV-2 (medium). A Set-Cookie without JSESSIONID (a load balancer's sticky cookie, a tracking cookie) on a later
     * answer replaces the session's JSESSIONID: the next command goes without the session (2002, a needless re-login;
     * or, if it comes on the retry's answer, a refusal of a command that was fine).
     */
    public function test_adv2_a_later_non_session_cookie_does_not_replace_jsessionid(): void
    {
        $transport = (new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=ABC123; Path=/epp'])
            ->queue(new HttpResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/v4.3/04-login-response.xml'), ['BIGipServerepp=1234.5678.0000; path=/']))
            ->result(1000);

        $this->session($transport)->command(self::RENEW);

        $this->assertStringContainsString('JSESSIONID=ABC123', $transport->sent[2]['headers']['Cookie']);
    }

    /**
     * ADV-3 (medium). The password is kept as a private property: __debugInfo hides it from var_dump/print_r, but
     * Symfony's VarDumper (dump(), dd(), the error page, Telescope) reads the properties themselves, and so do
     * var_export and an (array) cast. A dd($session) in tinker shows the registrar's password.
     */
    public function test_adv3_the_password_is_not_shown_by_dump_var_export_or_an_array_cast(): void
    {
        $config = $this->config();
        $session = $this->session(new FakeTransport);

        $leaks = [];
        foreach (['config' => $config, 'session' => $session] as $name => $object) {
            if (class_exists(VarCloner::class)) {
                $dump = (string) (new CliDumper)->dump((new VarCloner)->cloneVar($object), true);
                if (str_contains($dump, self::PASSWORD)) {
                    $leaks[] = "{$name}: VarDumper";
                }
            }
            if (str_contains(var_export($object, true), self::PASSWORD)) {
                $leaks[] = "{$name}: var_export";
            }
            if (str_contains(print_r((array) $object, true), self::PASSWORD)) {
                $leaks[] = "{$name}: (array)";
            }
        }

        $this->assertSame([], $leaks);
    }

    /**
     * ADV-4 (medium). A connect timeout (the registry down or firewalled: the request never left) is classified as
     * «maybe sent» (CURLE_OPERATION_TIMEDOUT is not in NEVER_SENT), so a command on a live session becomes
     * OutcomeUnknown: the domain is blocked for staff during every registry outage. curl knows nothing was sent
     * (CURLINFO_PRETRANSFER_TIME / CURLINFO_REQUEST_SIZE are 0). 192.0.2.1 is TEST-NET-1 (never answers); where the
     * network refuses it at once the test passes trivially (errno 7, already NEVER_SENT).
     */
    public function test_adv4_a_connect_timeout_is_never_sent(): void
    {
        $response = (new CurlTransport(timeout: 5, connectTimeout: 1))->post('https://192.0.2.1:700/epp/proxy', '<epp/>', []);

        $this->assertSame(0, $response->status);
        $this->assertTrue($response->neverSent, 'connect timeout: ' . $response->error);
    }

    /** ADV-5 (low). epp-1.0.xsd clIDType is a token of 3 to 16: a 1-2 character clID gives a frame invalid to the schema. */
    public function test_adv5_a_client_id_shorter_than_3_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('ab', 'abcdef12');
    }

    /** ADV-6 (low). A control character in the password is not valid XML 1.0: saveXML emits a frame that is not well-formed. */
    public function test_adv6_a_password_with_a_control_character_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('myip1', "abc\x01defgh");
    }

    /**
     * ADV-7 (low). The new password of login($newPassword) is never checked (pwType: 6 to 16, no control characters,
     * no outer spaces). A bad one is sent, and (with ADV-1) its outcome is misread; it should be refused before
     * anything is sent.
     */
    public function test_adv7_an_invalid_new_password_is_rejected_before_sending(): void
    {
        $transport = $this->loggedIn();

        $thrown = $this->thrown(fn () => $this->session($transport)->login('abc'));

        $this->assertSame([InvalidArgumentException::class, []], [$thrown, $transport->sent]);
    }

    /**
     * ADV-8 (low). The registry ends an idle session after 15 minutes (guide v4.3): a maxIdle above 900 (or 0 or
     * negative) is accepted silently. Above 900 every command after a pause finds the session gone (a 2002 round
     * trip); 0 or less logs in again before every command.
     */
    public function test_adv8_max_idle_is_within_the_registrys_15_minutes(): void
    {
        $accepted = [];
        foreach ([0, -1, 901, 3600] as $maxIdle) {
            if ($this->thrown(fn () => $this->config($maxIdle)) === null) {
                $accepted[] = $maxIdle;
            }
        }

        $this->assertSame([], $accepted);
    }

    /**
     * ADV-9 (low). A cookie value with a control character (a bare CR survives curl's header split) is kept and
     * replayed in the Cookie header: header injection towards the registry's proxy. Only a cookie-octet value should
     * be kept.
     */
    public function test_adv9_a_cookie_with_control_characters_is_not_replayed(): void
    {
        $transport = (new FakeTransport)
            ->fixture('02-hello-response.xml', ["JSESSIONID=ABC\rX-Injected: 1; Path=/"])
            ->fixture('04-login-response.xml')
            ->result(1000);

        $this->session($transport)->command(self::RENEW);

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $transport->sent[1]['headers']['Cookie'] ?? '');
    }

    /**
     * ADV-10 (low). Whitespace before the XML declaration (a JSP/servlet proxy's stray newline) makes every answer
     * unreadable: «XML declaration allowed only at the start». Fails safe (OutcomeUnknown), but a renewal that was
     * done, or refused, then blocks the domain for staff. The leading whitespace can be dropped before parsing.
     */
    public function test_adv10_leading_whitespace_before_the_declaration_is_read(): void
    {
        $xml = "\r\n  " . (string) file_get_contents(__DIR__ . '/fixtures/v4.3/52-domain-renew-response.xml');

        $this->assertSame(1000, Response::parse($xml)->code);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // REG: hypotheses that held
    // ---------------------------------------------------------------------------------------------------------------

    /** A timeout, a dropped connection, a truncated 200, HTML, a greeting, an empty 200: OutcomeUnknown, sent once. */
    public function test_reg_no_usable_answer_to_a_command_is_unknown_and_never_resent(): void
    {
        $answers = [
            'timeout' => new HttpResponse(0, '', [], 'timed out', neverSent: false),
            'truncated 200' => new HttpResponse(200, substr($this->body('<result code="1000"><msg>ok</msg></result>'), 0, 120)),
            'html 200' => new HttpResponse(200, '<html><body>Proxy Error</body></html>'),
            'empty 200' => new HttpResponse(200, ''),
            'greeting' => new HttpResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/v4.3/02-hello-response.xml')),
            'http 502' => new HttpResponse(502, 'Bad gateway'),
            'http 302' => new HttpResponse(302, ''),
            'result 999' => new HttpResponse(200, $this->body('<result code="999"><msg>x</msg></result>')),
            'result 3000' => new HttpResponse(200, $this->body('<result code="3000"><msg>x</msg></result>')),
            'no code' => new HttpResponse(200, $this->body('<result><msg>x</msg></result>')),
            '2400' => new HttpResponse(200, $this->body('<result code="2400"><msg>x</msg></result>')),
            '2500' => new HttpResponse(200, $this->body('<result code="2500"><msg>x</msg></result>')),
        ];
        foreach ($answers as $name => $answer) {
            $transport = $this->loggedIn()->queue($answer);
            $this->assertSame(OutcomeUnknown::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)), $name);
            $this->assertSame(['hello', 'login', 'renew'], $transport->kinds(), $name);
        }
    }

    /** 2002, re-login, then a timeout: unknown, never a third send; 2002 twice: refused, sent exactly twice. */
    public function test_reg_the_2002_retry_happens_once_and_only_on_2002(): void
    {
        // The 2002 path is a new session (logout, hello, login: code review 1 of #184).
        $transport = $this->loggedIn()->result(2002)->fixture('08-logout-response.xml')->fixture('02-hello-response.xml', ['JSESSIONID=N'])
            ->fixture('04-login-response.xml')->queue(new HttpResponse(0, '', [], 'timed out'));
        $this->assertSame(OutcomeUnknown::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)));
        $this->assertSame(2, count(array_filter($transport->kinds(), fn ($k) => $k === 'renew')));

        $transport = $this->loggedIn()->result(2002)->fixture('08-logout-response.xml')->fixture('02-hello-response.xml', ['JSESSIONID=N'])
            ->fixture('04-login-response.xml')->result(2002);
        $this->assertSame(Refused::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)));
        $this->assertSame(2, count(array_filter($transport->kinds(), fn ($k) => $k === 'renew')));

        // A 2002 whose re-login fails: NotSent (the command was refused, then never resent).
        $transport = $this->loggedIn()->result(2002)->fixture('08-logout-response.xml')->fixture('02-hello-response.xml', ['JSESSIONID=N'])
            ->result(2200, 'Λάθος ταυτοποίηση χρήστη');
        $this->assertSame(NotSent::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)));
        $this->assertSame(1, count(array_filter($transport->kinds(), fn ($k) => $k === 'renew')));
    }

    /** Refusals stay refusals (not blocking the domain); 1001 and 1500 are success; the first <result> counts. */
    public function test_reg_refusals_and_success_codes(): void
    {
        foreach ([2001, 2003, 2005, 2101, 2104, 2105, 2201, 2302, 2303, 2304, 2305, 2306, 2308] as $code) {
            $transport = $this->loggedIn()->result($code);
            $this->assertSame(Refused::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)), (string) $code);
        }
        foreach ([1000, 1001, 1500] as $code) {
            $this->assertSame($code, $this->session($this->loggedIn()->result($code))->command(self::RENEW)->code);
        }
        $two = Response::parse($this->body('<result code="2306"><msg>a</msg></result><result code="2004"><msg>b</msg></result>'));
        $this->assertSame([2306, 'a'], [$two->code, $two->message]);
        $this->assertSame(Refused::class, $this->thrown(fn () => $this->session($this->loggedIn()->fixture('38.1-domain-create-response-when-domain-is-already-registered.xml'))->command(self::RENEW)));
    }

    /** A command that never left (DNS, connection refused, TLS) on a live session: NotSent. */
    public function test_reg_a_command_that_never_left_is_not_sent(): void
    {
        $transport = $this->loggedIn()->queue(new HttpResponse(0, '', [], 'Could not resolve host', neverSent: true));

        $this->assertSame(NotSent::class, $this->thrown(fn () => $this->session($transport)->command(self::RENEW)));
    }

    /** XXE, billion laughs, an external DTD, non-UTF-8 bytes: OutcomeUnknown, nothing read from disk. */
    public function test_reg_hostile_xml_is_not_read(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE epp [<!ENTITY x SYSTEM "file:///etc/passwd">]><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>&x;</msg></result></response></epp>';
        $laughs = '<?xml version="1.0"?><!DOCTYPE epp [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;"><!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;"><!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;"><!ENTITY g "&f;&f;&f;&f;&f;&f;&f;&f;&f;&f;">]><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>&g;</msg></result></response></epp>';
        $external = '<?xml version="1.0"?><!DOCTYPE epp SYSTEM "http://127.0.0.1:1/x.dtd"><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result></response></epp>';
        $latin = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><epp xmlns=\"urn:ietf:params:xml:ns:epp-1.0\"><response><result code=\"1000\"><msg>\xE1\xE2\xFF</msg></result></response></epp>";

        foreach (['xxe' => $xxe, 'laughs' => $laughs, 'external' => $external, 'latin' => $latin] as $name => $xml) {
            try {
                Response::parse($xml);
                $this->fail("{$name} was read");
            } catch (OutcomeUnknown $e) {
                $this->assertStringNotContainsString('root:', $e->getMessage(), $name);
            }
        }
    }

    /** A UTF-8 BOM and a document in another declared encoding are read. */
    public function test_reg_bom_and_declared_encodings_are_read(): void
    {
        $this->assertSame(1000, Response::parse("\xEF\xBB\xBF" . $this->body('<result code="1000"><msg>ok</msg></result>'))->code);
        $greek = '<?xml version="1.0" encoding="ISO-8859-7"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="2303"><msg>' . "\xC1\xED\xF4" . '</msg></result></response></epp>';
        $this->assertSame([2303, 'Αντ'], [Response::parse($greek)->code, Response::parse($greek)->message]);
    }

    /** The idle limit: exactly maxIdle reuses the session; past it, logout, hello, login; a clock going back reuses it. */
    public function test_reg_idle_edges(): void
    {
        $transport = $this->loggedIn()->result(1000)->result(1000);
        $session = $this->session($transport, 300);
        $session->command(self::RENEW);
        $this->now += 300;
        $session->command(self::RENEW);
        $this->assertSame(['hello', 'login', 'renew', 'renew'], $transport->kinds());

        $transport = $this->loggedIn()->result(1000)->fixture('08-logout-response.xml')->fixture('02-hello-response.xml', ['JSESSIONID=NEW'])
            ->fixture('04-login-response.xml')->result(1000);
        $session = $this->session($transport, 300);
        $session->command(self::RENEW);
        $this->now += 300.001;
        $session->command(self::RENEW);
        $this->assertSame(['hello', 'login', 'renew', 'logout', 'hello', 'login', 'renew'], $transport->kinds());
        $this->assertSame('JSESSIONID=NEW', $transport->sent[6]['headers']['Cookie']);

        // Clock backwards: not idle; a session the registry ended is still caught by 2002.
        $transport = $this->loggedIn()->result(1000)->result(2002)->fixture('08-logout-response.xml')
            ->fixture('02-hello-response.xml', ['JSESSIONID=N'])->fixture('04-login-response.xml')->result(1000);
        $session = $this->session($transport, 300);
        $session->command(self::RENEW);
        $this->now -= 3600;
        $this->assertSame(1000, $session->command(self::RENEW)->code);
    }

    /** A command that timed out leaves the session as it was and does not refresh the idle clock. */
    public function test_reg_state_after_a_failed_command(): void
    {
        $transport = $this->loggedIn()->queue(new HttpResponse(0, '', [], 'timed out'))
            ->fixture('08-logout-response.xml')->fixture('02-hello-response.xml')->fixture('04-login-response.xml')->result(1000);
        $session = $this->session($transport, 300);
        $this->thrown(fn () => $session->command(self::RENEW));
        $this->assertTrue($session->loggedIn());
        $this->now += 301; // measured from the last answer (the login), not from the timed-out send
        $session->command(self::RENEW);
        $this->assertSame(['hello', 'login', 'renew', 'logout', 'hello', 'login', 'renew'], $transport->kinds());
    }

    /** A new JSESSIONID on any answer (the login rotating the session id) is adopted. */
    public function test_reg_a_rotated_jsessionid_is_adopted(): void
    {
        $transport = (new FakeTransport)
            ->fixture('02-hello-response.xml', ['JSESSIONID=PRE; Path=/epp'])
            ->fixture('04-login-response.xml', ['JSESSIONID=POST; Path=/epp; HttpOnly'])
            ->result(1000);

        $this->session($transport)->command(self::RENEW);

        $this->assertSame('JSESSIONID=POST', $transport->sent[2]['headers']['Cookie']);
    }

    /** The password is in no message, previous, trace, json or serialised copy, on any failure path. */
    public function test_reg_the_password_is_not_in_exceptions_json_or_serialize(): void
    {
        $paths = [
            'login refused' => (new FakeTransport)->fixture('02-hello-response.xml')->result(2200, 'Λάθος ταυτοποίηση'),
            'login timeout' => (new FakeTransport)->fixture('02-hello-response.xml')->queue(new HttpResponse(0, '', [], 'timeout')),
            'hello garbage' => (new FakeTransport)->queue(new HttpResponse(200, '<x/>')),
        ];
        foreach ($paths as $name => $transport) {
            try {
                $this->session($transport)->command(self::RENEW);
                $this->fail($name);
            } catch (GrEppException $e) {
                for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                    $this->assertStringNotContainsString(self::PASSWORD, $x->getMessage() . $x->getTraceAsString() . print_r($x->getTrace(), true), $name);
                }
            }
        }
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($this->config()));
        $this->assertNotNull($this->thrown(fn () => serialize($this->session(new FakeTransport))));
        $this->assertNotNull($this->thrown(fn () => serialize($this->config())));
    }
}

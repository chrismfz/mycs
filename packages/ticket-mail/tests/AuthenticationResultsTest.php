<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\Parser;
use PHPUnit\Framework\TestCase;

/** §3 Β: only our server's Authentication-Results, on top, vouches for a From. */
final class AuthenticationResultsTest extends TestCase
{
    private const OURS = 'mymail.myip.test';

    private function authenticated(array $headers, string $from = 'maria@example.com'): bool
    {
        return AuthenticationResults::verdict($headers, self::OURS, $from)->authenticated;
    }

    public function test_our_dmarc_pass_vouches_for_the_from(): void
    {
        $this->assertTrue($this->authenticated(['mymail.myip.test; dkim=pass header.d=example.com; spf=pass smtp.mailfrom=maria@example.com; dmarc=pass (p=reject) header.from=example.com']));
        $this->assertTrue($this->authenticated(['MyMail.MyIP.test 1; dmarc=pass header.from=example.com']), 'the id in any case, with a version');
    }

    public function test_a_forged_header_below_ours_counts_for_nothing(): void
    {
        // The sender wrote the second; our server added the first, on top.
        $this->assertFalse($this->authenticated([
            'mymail.myip.test; dmarc=fail header.from=example.com',
            'mymail.myip.test; dmarc=pass header.from=example.com',
        ]));
        $this->assertFalse($this->authenticated([
            'mymail.myip.test; spf=softfail smtp.mailfrom=attacker@evil.test',
            'evil.test; dmarc=pass header.from=example.com',
        ]));
    }

    public function test_another_servers_results_count_for_nothing(): void
    {
        $this->assertFalse($this->authenticated(['mx.google.com; dmarc=pass header.from=example.com']));
        $this->assertFalse($this->authenticated([]));
        $this->assertFalse(AuthenticationResults::verdict(['mymail.myip.test; dmarc=pass header.from=example.com'], '', 'maria@example.com')->authenticated, 'no authserv-id set: nothing is trusted');
    }

    public function test_a_pass_must_be_for_the_froms_domain(): void
    {
        $this->assertFalse($this->authenticated(['mymail.myip.test; dmarc=pass header.from=evil.test']));
        $this->assertFalse($this->authenticated(['mymail.myip.test; dkim=pass header.d=evil.test; spf=pass smtp.mailfrom=x@evil.test']));
        $this->assertTrue($this->authenticated(['mymail.myip.test; dkim=pass header.d=example.com'], 'maria@mail.example.com'), 'relaxed alignment');
        $this->assertFalse($this->authenticated(['mymail.myip.test; dkim=pass header.d=com'], 'maria@example.com'), 'never a bare TLD');
        $this->assertFalse($this->authenticated(['mymail.myip.test; dkim=pass header.d=ample.com'], 'maria@example.com'), 'a suffix that is not a parent');
        $this->assertTrue($this->authenticated(['mymail.myip.test; spf=pass smtp.mailfrom=bounce@example.com']));
    }

    public function test_a_pass_inside_a_comment_is_no_pass(): void
    {
        $this->assertFalse($this->authenticated(['mymail.myip.test; dmarc=fail (dmarc=pass header.from=example.com) header.from=example.com']));
        $this->assertFalse($this->authenticated(['mymail.myip.test (dmarc=pass header.from=example.com); spf=fail']));
        $this->assertFalse($this->authenticated(['mymail.myip.test; dkim=neutral; dmarc=none header.from=example.com']));
    }

    public function test_the_parser_keeps_them_top_first(): void
    {
        $email = Parser::parse(Eml::make(['Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.com',
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com']));

        $this->assertStringContainsString('dmarc=fail', $email->authenticationResults[0]);
        $this->assertFalse(AuthenticationResults::verdict($email->authenticationResults, self::OURS, $email->fromEmail)->authenticated);
    }

    /** Adversarial review of #244 (F1): a «;» or a result inside a quoted value is the sender's text, never ours. */
    public function test_a_quoted_value_is_one_token(): void
    {
        $this->assertFalse($this->authenticated(['mymail.myip.test; spf=pass smtp.mailfrom="x;dmarc=pass header.from=example.com y"@evil.test; dmarc=fail header.from=example.com']));
        $this->assertFalse($this->authenticated(['mymail.myip.test; spf=pass smtp.mailfrom="a\\";dmarc=pass header.from=example.com"@evil.test']));
        $this->assertFalse($this->authenticated(['mymail.myip.test; dmarc=pass'], 'maria@example.com'), 'no header.from: no pass');
        // A subdomain's pass never vouches for its parent's address: a third party may control it (security audit API-10).
        $this->assertFalse($this->authenticated(['mymail.myip.test; dkim=pass header.d=mail.example.com'], 'maria@example.com'), 'a subdomain does not align');
        $this->assertFalse($this->authenticated(['mymail.myip.test; spf=pass smtp.mailfrom=x@tenant.example.com'], 'maria@example.com'));
    }

    /**
     * Sweep 7 (S7-TICKETS-MAIL-1). What a server that echoes the envelope sender in its SPF comment and again as
     * smtp.mailfrom (rspamd, others alike) writes for a mail that passes SPF for the sender's own domain only.
     */
    private static function echoed(string $envelopeSender, string $fromDomain = 'example.com'): string
    {
        return "mymail.myip.test; dkim=none; dmarc=fail header.from={$fromDomain} (policy=none); "
            . "spf=pass (mymail.myip.test: domain of {$envelopeSender} designates 203.0.113.9 as permitted sender) smtp.mailfrom={$envelopeSender}";
    }

    private function reason(string $header, string $from = 'maria@example.com'): string
    {
        $verdict = AuthenticationResults::verdict([$header], self::OURS, $from);
        $this->assertFalse($verdict->authenticated, $header);

        return $verdict->reason;
    }

    public function test_an_envelope_sender_never_writes_the_servers_results(): void
    {
        $this->assertSame('no_aligned_pass', $this->reason(self::echoed('bounce@evil.test')), 'the honest one reads, and vouches for nothing');

        foreach ([
            'a quoted local part that closes the comment' => '"a) smtp.mailfrom=example.com ("@evil.test',
            'the same, with statements of its own' => '"a) ; dkim=pass header.d=example.com; dmarc=pass header.from=example.com; x=y ("@evil.test',
            'parentheses without quotes' => 'a) smtp.mailfrom=example.com (b@evil.test',
            'a closing parenthesis alone' => 'a) smtp.mailfrom=example.com b@evil.test',
            'two comments left open, to swallow the second copy' => 'a) ; dkim=pass header.d=example.com ((b@evil.test',
            'a quote that the comment would end' => 'a" ) ; dkim=pass header.d=example.com ( "b@evil.test',
            'a backslash before the parenthesis' => '"a\\) smtp.mailfrom=example.com \\("@evil.test',
            'a quoted local part with ; and =' => '"a;dmarc=pass header.from=example.com;b=c"@evil.test',
            'an opening parenthesis alone' => 'a(b@evil.test',
        ] as $what => $envelopeSender) {
            $this->assertSame('unreadable_results', $this->reason(self::echoed($envelopeSender)), $what);
            $this->assertNull(AuthenticationResults::parse(self::echoed($envelopeSender)), $what);
        }
    }

    public function test_a_header_that_does_not_read_cleanly_vouches_for_nothing(): void
    {
        foreach ([
            'a comment never closed' => 'mymail.myip.test; dmarc=pass header.from=example.com (policy=none',
            'a comment inside a comment' => 'mymail.myip.test; dmarc=pass (policy (none)) header.from=example.com',
            'a parenthesis inside quotes in a comment' => 'mymail.myip.test; dmarc=pass ("a)b") header.from=example.com',
            'a stray closing parenthesis' => 'mymail.myip.test; dmarc=pass header.from=example.com)',
            'a quoted value' => 'mymail.myip.test; dmarc=pass header.from="example.com"',
            'a backslash' => 'mymail.myip.test; dmarc=pass header.from=example.com\\',
            'a control character' => "mymail.myip.test; dmarc=pass\0 header.from=example.com",
            'a word that is no name=value' => 'mymail.myip.test; dmarc=pass really header.from=example.com',
            'spaces around the equals sign' => 'mymail.myip.test; dmarc = pass header.from = example.com',
            'a comment inside a value' => 'mymail.myip.test; dmarc=pass header.from=exa(x)mple.com',
            'a statement that opens with no method' => 'mymail.myip.test; header.from=example.com dmarc=pass',
            'the same property twice' => 'mymail.myip.test; spf=pass smtp.mailfrom=x@evil.test smtp.mailfrom=example.com',
            'two DMARC results' => 'mymail.myip.test; dmarc=fail header.from=example.com; dmarc=pass header.from=example.com',
            'DKIM none and DKIM pass' => 'mymail.myip.test; dkim=none; dkim=pass header.d=example.com',
            'words after the authserv-id' => 'mymail.myip.test dmarc=pass header.from=example.com',
            'an authserv-id that is no host name' => "mym\u{0430}il.myip.test; dmarc=pass header.from=example.com",
        ] as $what => $header) {
            $this->assertSame('unreadable_results', $this->reason($header), $what);
        }
    }

    public function test_a_result_is_read_only_as_a_word_of_its_own_statement(): void
    {
        // Inside a comment, inside another property's value, or under another method: never a pass for the From.
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dmarc=fail (dmarc=pass header.from=example.com) header.from=example.com'));
        $this->assertSame('no_aligned_pass', $this->reason("mymail.myip.test; spf=fail\r\n\t(dmarc=pass\r\n\theader.from=example.com)\r\n\tsmtp.mailfrom=x@evil.test"), 'folded');
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; spf=pass smtp.mailfrom=header.from=example.com@evil.test; dmarc=fail header.from=example.com'));
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dkim=fail header.d=evil.test header.i=dmarc=pass@example.com'));
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dkim=pass header.d=evil.test header.from=example.com smtp.mailfrom=example.com'), "DKIM's domain is its header.d");
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; x-dmarc=pass header.from=example.com; dmarcx=pass header.from=example.com'));
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dmarc=pass header.from=example.org'), 'DMARC passed for another From than the one shown');
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dmarc=pass header.from=example.com.evil.test'));
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; dmarc=passed header.from=example.com'));
        $this->assertSame('no_aligned_pass', $this->reason('mymail.myip.test; none'));
    }

    public function test_only_our_servers_topmost_header_counts(): void
    {
        $pass = 'dmarc=pass header.from=example.com';
        foreach (['mymail.myip.test.evil.test', 'xmymail.myip.test', 'mymail-myip.test', 'mymail.myip.test.', 'myip.test', 'mymail.myip.test/1'] as $lookalike) {
            $this->assertFalse($this->authenticated(["{$lookalike}; {$pass}"]), $lookalike);
        }
        // The sender's own, with our authserv-id, sits below what our server wrote: under an honest «fail», under one
        // that does not read, and under another server's.
        $this->assertFalse($this->authenticated(['mymail.myip.test; dmarc=fail header.from=example.com', "mymail.myip.test; {$pass}"]));
        $this->assertFalse($this->authenticated([self::echoed('"a) ("@evil.test'), "mymail.myip.test; {$pass}"]));
        $this->assertFalse($this->authenticated(["mx.elsewhere.test; {$pass}", "mymail.myip.test; {$pass}"]));

        $email = Parser::parse(Eml::make(["Authentication-Results: mymail.myip.test;\r\n\tdmarc=fail header.from=example.com",
            "Authentication-Results: mymail.myip.test;\r\n\t{$pass}", "X-Authentication-Results: mymail.myip.test; {$pass}"]));
        $this->assertCount(2, $email->authenticationResults);
        $this->assertFalse(AuthenticationResults::verdict($email->authenticationResults, self::OURS, $email->fromEmail)->authenticated);
    }

    public function test_upper_and_lower_case_read_the_same(): void
    {
        $this->assertTrue($this->authenticated(['MYMAIL.MYIP.TEST; DMARC=PASS (P=REJECT) Header.From=EXAMPLE.COM'], 'Maria@Example.COM'));
        $this->assertFalse($this->authenticated(['MYMAIL.MYIP.TEST; DMARC=PASS Header.From=EXAMPLE.ORG']));
    }

    public function test_a_second_from_is_counted_for_the_router_to_refuse(): void
    {
        $email = Parser::parse(Eml::make(['From' => 'maria@example.com, owner@victim.example',
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com']));

        $this->assertSame(2, $email->fromCount);
    }

    /** Invented samples in the style of common servers (ours would be the authserv-id): they still authenticate. */
    public function test_what_real_servers_write_still_vouches(): void
    {
        $samples = [
            'rspamd (mailcow)' => "mymail.myip.test;\r\n\tdkim=pass header.d=example.com header.s=dkim header.b=Qz8kLm1p;\r\n"
                . "\tdmarc=pass (policy=quarantine) header.from=example.com;\r\n"
                . "\tspf=pass (mymail.myip.test: domain of maria@example.com designates 192.0.2.25 as permitted sender) smtp.mailfrom=maria@example.com",
            'rspamd, forwarded with ARC' => "mymail.myip.test;\r\n\tdkim=pass header.d=example.com header.s=dkim header.b=Qz8kLm1p;\r\n"
                . "\tarc=pass (\"example.com:s=dkim:i=1\");\r\n\tdmarc=pass (policy=none) header.from=example.com;\r\n\tspf=softfail smtp.mailfrom=maria@example.com",
            'Postfix, OpenDMARC on top' => 'mymail.myip.test; dmarc=pass (p=none dis=none) header.from=example.com',
            'Postfix, OpenDKIM on top' => "mymail.myip.test;\r\n\tdkim=pass (2048-bit key; unprotected) header.d=example.com header.i=@example.com header.a=rsa-sha256 header.s=mail header.b=fR3xYz12;\r\n\tdkim-atps=neutral",
            'Postfix, policyd-spf on top' => 'mymail.myip.test; spf=pass (sender SPF authorized) smtp.mailfrom=example.com '
                . '(client-ip=192.0.2.25; helo=mail.example.com; envelope-from=maria@example.com; receiver=<UNKNOWN>)',
            'Exim' => "mymail.myip.test;\r\n\tiprev=pass (mail.example.com) smtp.remote-ip=2001:db8::25;\r\n\tspf=pass smtp.mailfrom=example.com;\r\n"
                . "\tdkim=pass header.d=example.com header.s=sel1 header.a=rsa-sha256;\r\n\tdmarc=pass header.from=example.com;\r\n\tarc=none",
            'a hosted provider' => "mymail.myip.test;\r\n       dkim=pass header.i=@example.com header.s=20240605 header.b=Kd9+aB/3;\r\n"
                . "       spf=pass (mymail.myip.test: domain of maria@example.com designates 192.0.2.25 as permitted sender) smtp.mailfrom=maria@example.com;\r\n"
                . '       dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=example.com',
            'another hosted provider' => "mymail.myip.test; spf=pass (sender IP is 192.0.2.25)\r\n smtp.mailfrom=example.com; dkim=pass (signature was verified)\r\n"
                . " header.d=example.com;dmarc=pass action=none\r\n header.from=example.com;compauth=pass reason=100",
            'a bounce, with no envelope sender' => 'mymail.myip.test 1; spf=none smtp.mailfrom=<> smtp.helo=mail.example.com; dmarc=pass header.from=example.com;',
        ];
        foreach ($samples as $server => $header) {
            $verdict = AuthenticationResults::verdict([$header], self::OURS, 'maria@example.com');
            $this->assertTrue($verdict->authenticated, "{$server}: {$verdict->reason}");
            // Through a real message too: folded as written.
            $email = Parser::parse(Eml::make(["Authentication-Results: {$header}"]));
            $this->assertTrue(AuthenticationResults::verdict($email->authenticationResults, self::OURS, $email->fromEmail)->authenticated, "{$server}, as a message");
        }
        // The same headers, for a From they do not name.
        foreach ($samples as $server => $header) {
            $this->assertFalse($this->authenticated([$header], 'owner@victim.example'), $server);
        }
    }
}

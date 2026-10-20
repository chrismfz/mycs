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
}

<?php

namespace MyCS\BankAlerts\Tests;

use MyCS\BankAlerts\Authenticity;
use PHPUnit\Framework\TestCase;

/**
 * Independent and adversarial review of #771 (S7-TICKETS-MAIL-1), the bank alerts' side. Invented domains only:
 * mx.example.test is «our» server, example.test our sealing domain, bank.example the bank, attacker.example not.
 * The rspamd samples are as its source writes them (see the ticket-mail review test).
 */
final class Sweep7AuthenticityReviewTest extends TestCase
{
    /** @param  list<string>  $arcResults */
    private function verdict(string $top, array $arcSeals = [], array $arcSignatures = [], array $arcResults = []): string
    {
        $verdict = Authenticity::verdict([$top], $arcSeals, $arcSignatures, $arcResults, 'mx.example.test', 'example.test', 'alerts@bank.example');

        return ($verdict->authenticated ? 'authentic: ' : 'not authentic: ') . $verdict->reason;
    }

    /**
     * REV-771-1 (false reject, high). rspamd quotes header.b when the signature starts with a «/» among its first 8
     * characters (about 12% of signatures): the bank's genuine alert is then «unreadable_results» and is not matched to
     * its invoice. At random, one alert in eight waits for staff.
     */
    public function test_rev_771_1_the_banks_genuine_alert_with_a_quoted_signature_start_is_authentic(): void
    {
        $genuine = "mx.example.test;\r\n\tdkim=pass header.d=bank.example header.s=sel1 header.b=\"Qz8/Lm1p\";\r\n\tdmarc=pass (policy=reject) header.from=bank.example;\r\n"
            . "\tspf=pass (mx.example.test: domain of bounces@bank.example designates 192.0.2.25 as permitted sender) smtp.mailfrom=bounces@bank.example";

        $this->assertSame('authentic: dkim_dmarc_pass', $this->verdict($genuine));
    }

    /**
     * REV-771-2 (false reject, high if the bank's sender is one). A bank that sends through a mailing platform has a
     * VERP envelope sender («…=…@…»); rspamd quotes it, and then none of that bank's alerts is ever authentic.
     */
    public function test_rev_771_2_the_banks_genuine_alert_with_a_verp_envelope_sender_is_authentic(): void
    {
        $sender = '"bounce+7f3a.alerts=bank.example@mailer.bank.example"';
        $genuine = "mx.example.test;\r\n\tdkim=pass header.d=bank.example header.s=sel1 header.b=Qz8kLm1p;\r\n\tdmarc=pass (policy=reject) header.from=bank.example;\r\n"
            . "\tspf=pass (mx.example.test: domain of {$sender} designates 192.0.2.25 as permitted sender) smtp.mailfrom={$sender}";

        $this->assertSame('authentic: dkim_dmarc_pass', $this->verdict($genuine));
    }

    /**
     * REV-771-1 again, forwarded inside our server (ARC): rspamd writes its ARC-Authentication-Results with the same
     * quoting, so the forwarded genuine alert is refused the same way («arc_results_not_ours»).
     */
    public function test_rev_771_1_the_forwarded_genuine_alert_with_a_quoted_signature_start_is_authentic(): void
    {
        $top = "mx.example.test;\r\n\tdkim=pass header.d=bank.example header.s=sel1 header.b=\"Qz8/Lm1p\";\r\n\tarc=pass (\"example.test:s=dkim:i=1\");\r\n"
            . "\tdmarc=pass (policy=reject) header.from=bank.example;\r\n\tspf=softfail (mx.example.test: 192.0.2.7 is neither permitted nor denied by domain of bounces@bank.example) smtp.mailfrom=bounces@bank.example";
        $this->assertSame('authentic: dkim_dmarc_pass', $this->verdict($top));

        // The copy our server forwarded when the bank's signature no longer verifies there: only our sealed i=1 says so.
        $broken = "mx.example.test;\r\n\tdkim=fail (\"body hash did not verify\") header.d=bank.example header.s=sel1 header.b=\"Qz8/Lm1p\";\r\n"
            . "\tarc=pass (\"example.test:s=dkim:i=1\");\r\n\tdmarc=none";
        $sealed = "i=1; mx.example.test;\r\n\tdkim=pass header.d=bank.example header.s=sel1 header.b=\"Qz8/Lm1p\";\r\n\tdmarc=pass (policy=reject) header.from=bank.example";
        $this->assertSame('authentic: arc:dkim_dmarc_pass', $this->verdict($broken,
            ['i=1; a=rsa-sha256; d=example.test; s=dkim; cv=none; t=1792740475; b=AAAA'],
            ['i=1; a=rsa-sha256; d=example.test; s=dkim; h=from:subject; bh=CCCC; b=DDDD'], [$sealed]));
    }

    /**
     * REV-771-4 (forgery, medium: not the pilot's server). The PR names as its residual a server that echoes the
     * sender's text inside a comment only, «a clean extra DKIM statement for tickets». Where the server's own DMARC
     * result is not in the same header (Postfix with policyd-spf, OpenDKIM and OpenDMARC writes three headers; the PR's
     * tests accept each of them «on top»), the «one DMARC result» rule has nothing to count, and the same text writes
     * the bank's DKIM AND DMARC passes: an alert that marks an invoice paid. The header below is the PR's own
     * «Postfix, policyd-spf on top» sample with a sender-chosen envelope sender (whether policyd-spf escapes it was not
     * verified; rspamd does, see the report). If such a server is ever «ours», this must not read as authentic.
     */
    public function test_rev_771_4_text_echoed_in_a_comment_does_not_write_the_banks_dkim_and_dmarc(): void
    {
        $envelopeSender = 'a) ; dkim=pass header.d=bank.example ; dmarc=pass header.from=bank.example ; x=y (b@attacker.example';
        $header = 'mx.example.test; spf=pass (sender SPF authorized) smtp.mailfrom=attacker.example '
            . "(client-ip=203.0.113.9; helo=mail.attacker.example; envelope-from={$envelopeSender}; receiver=<UNKNOWN>)";

        $this->assertStringStartsWith('not authentic', $this->verdict($header));
    }
}

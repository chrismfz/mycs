<?php

namespace MyCS\BankAlerts\Tests;

use MyCS\BankAlerts\AlertParser;
use MyCS\BankAlerts\BankAlert;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of #450: mails an attacker can send that the parser must not believe, or must not read as it does.
 * All data synthetic; mx.example.test / example.test are «our» server and sealing domain, attacker.example the attacker's.
 */
final class AdversarialTest extends TestCase
{
    private const SERVER = 'mx.example.test';

    private const SEALER = 'example.test';

    private function parse(string $raw): ?BankAlert
    {
        return AlertParser::parse($raw, self::SERVER, self::SEALER);
    }

    /** What our server writes on top when the chain an attacker built validates (their key, their DNS). */
    private const ARC_PASS = "Authentication-Results: mx.example.test;\r\n\tarc=pass (\"attacker.example:s=s1:i=1\")\r\n";

    /** The attacker's own ARC-Authentication-Results i=1: any text they like, with our authserv-id. */
    private const THEIR_AAR = "ARC-Authentication-Results: i=1; mx.example.test;\r\n\tdkim=pass header.d=eurobank.gr;\r\n\tdmarc=pass (policy=reject) header.from=eurobank.gr\r\n";

    /**
     * DKIM/ARC tag names are case-sensitive and a verifier ignores a tag it does not know (RFC 6376 §3.2): «D=» is
     * no «d=». The attacker's seal is valid under d=attacker.example, so our server says arc=pass; Authenticity reads
     * the tag case-insensitively and takes «D=example.test» for our seal.
     */
    public function test_an_uppercase_d_tag_in_the_attackers_seal_is_not_our_seal(): void
    {
        $auth = self::ARC_PASS
            . "ARC-Seal: i=1; a=rsa-sha256; D=example.test; d=attacker.example; s=s1; cv=none; t=1792740475;\r\n\tb=AAAA\r\n"
            . self::THEIR_AAR;

        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '9.000,00', $auth));

        $this->assertNotNull($alert);
        $this->assertFalse($alert->authentic, 'a seal under d=attacker.example made authentic by a «D=» tag: ' . $alert->authenticity);
    }

    /**
     * A tag twice is a malformed signature; a verifier that keeps the last one validates the attacker's d=, while
     * Authenticity takes the first. Never ours unless d= is there exactly once.
     */
    public function test_a_seal_with_two_d_tags_is_not_our_seal(): void
    {
        $auth = self::ARC_PASS
            . "ARC-Seal: i=1; a=rsa-sha256; d=example.test; d=attacker.example; s=s1; cv=none; t=1792740475;\r\n\tb=AAAA\r\n"
            . self::THEIR_AAR;

        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '9.000,00', $auth));

        $this->assertFalse($alert?->authentic, 'two d= tags, the first ours: ' . $alert?->authenticity);
    }

    /**
     * ARC (RFC 8617 §5.2): the verifier checks every seal but only the LATEST ARC-Message-Signature. Our i=1 set
     * vouches for the body as it was then; a later hop (i=2, the attacker's own keys) may have changed the body, the
     * amount and the reference, and the chain still passes. Our setup has one hop: a set after ours means the mail
     * left our server and came back, and our i=1 no longer vouches for what is in it.
     */
    public function test_a_later_arc_set_after_ours_means_our_seal_no_longer_vouches_for_the_body(): void
    {
        $auth = "Authentication-Results: mx.example.test;\r\n\tarc=pass (\"attacker.example:s=s1:i=2\")\r\n"
            . "ARC-Seal: i=2; a=rsa-sha256; d=attacker.example; s=s1; cv=pass; t=1792740999;\r\n\tb=BBBB\r\n"
            . "ARC-Message-Signature: i=2; a=rsa-sha256; d=attacker.example; s=s1; h=from:subject; bh=CCCC; b=DDDD\r\n"
            . "ARC-Authentication-Results: i=2; relay.attacker.example; arc=pass\r\n"
            . Fixtures::forwardedByUs('eurobank.gr');

        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '9.000,00', $auth));

        $this->assertFalse($alert?->authentic, 'a body re-signed by a later hop believed on the strength of our i=1: ' . $alert?->authenticity);
    }

    /**
     * For a mail that marks invoices paid, a DMARC pass that rests on SPF alone (no DKIM of the bank) is weak: the
     * bank's SPF record may include a shared sender (an ESP, a cloud relay) any customer of it can send through.
     * The bank's alerts are DKIM signed (the fixtures' i=1 says so); ask for its DKIM.
     */
    public function test_an_spf_only_pass_without_the_banks_dkim_is_not_enough(): void
    {
        $auth = "Authentication-Results: mx.example.test;\r\n\tdkim=none;\r\n\tspf=pass smtp.mailfrom=bounces@eurobank.gr;\r\n"
            . "\tdmarc=pass (policy=reject) header.from=eurobank.gr\r\n";

        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '9.000,00', $auth));

        $this->assertFalse($alert?->authentic, 'SPF alignment alone: ' . $alert?->authenticity);
    }

    /**
     * bank_movements.amount and .balance are decimal(17,2): 15 digits before the point. Anyone can send a mail From
     * eurobank.gr (it is stored, unauthentic); a 16-digit amount fails the insert on MariaDB («Out of range»), which
     * aborts the folder before its cursor is saved: every run after it stops at the same mail (see the feature test).
     */
    public function test_an_amount_or_balance_that_does_not_fit_the_column_is_no_alert(): void
    {
        $this->assertNull($this->parse(Fixtures::eurobank('πιστώθηκε', '1.234.567.890.123.456,00', 'X', '1,00', '')));
        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '1,00', 'X', '9.999.999.999.999.999,00', ''));
        $this->assertTrue($alert === null || $alert->balance === null, 'a balance that does not fit the column: ' . $alert?->balance);
    }

    /**
     * Round 2. The one-set rule counts the ARC headers the MIME parser sees; it does not see a header written with
     * whitespace before its colon («ARC-Seal : …», RFC 5322 obsolete syntax), it drops it. A verifier that reads the
     * obsolete syntax (RFC 5322 §4.5.2 says to accept it) sees an i=2 set after ours and validates the chain; we see one
     * set, ours, and believe a body the i=2 sealer may have rewritten (finding 3 of round 1 again). A mail with a
     * header we cannot read is no mail we vouch for.
     */
    public function test_an_arc_set_hidden_behind_whitespace_before_the_colon_is_still_a_second_set(): void
    {
        $auth = "Authentication-Results: mx.example.test;\r\n\tarc=pass (\"attacker.example:s=s1:i=2\")\r\n"
            . "ARC-Seal : i=2; a=rsa-sha256; d=attacker.example; s=s1; cv=pass; t=1792740999;\r\n\tb=BBBB\r\n"
            . "ARC-Message-Signature : i=2; a=rsa-sha256; d=attacker.example; s=s1; h=from:subject; bh=CCCC; b=DDDD\r\n"
            . "ARC-Authentication-Results : i=2; relay.attacker.example; arc=pass\r\n"
            . Fixtures::forwardedByUs('eurobank.gr');

        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '9.000,00', $auth));

        $this->assertFalse($alert?->authentic, 'a second ARC set our parser cannot see: ' . $alert?->authenticity);
    }
}

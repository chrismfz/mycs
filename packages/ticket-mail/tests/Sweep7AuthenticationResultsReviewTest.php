<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Independent and adversarial review of #771 (S7-TICKETS-MAIL-1), findings REV-771-n. Invented domains only.
 *
 * The «rspamd» samples follow what rspamd (the pilot's server, in mailcow) writes, read from its source
 * (lualib/lua_auth_results.lua, gen_auth_results): every value goes through lua_util.maybe_smtp_quote_value, which
 * wraps it in double quotes as soon as it holds one of «( ) < > , ; : \ " / [ ] ? =», a space or a tab. The samples
 * in the PR's own tests were invented without such a character, so they never met a quote.
 */
final class Sweep7AuthenticationResultsReviewTest extends TestCase
{
    private const OURS = 'mymail.myip.test';

    private const SPF = 'spf=pass (mymail.myip.test: domain of maria@example.com designates 192.0.2.25 as permitted sender) smtp.mailfrom=maria@example.com';

    private function verdict(string $header, string $from = 'maria@example.com'): string
    {
        $verdict = AuthenticationResults::verdict([$header], self::OURS, $from);

        return ($verdict->authenticated ? 'vouched: ' : 'not vouched: ') . $verdict->reason;
    }

    /**
     * REV-771-1 (false reject, high). header.b is the first 8 base64 characters of the DKIM signature; «/» is one of
     * the 64, so about one signature in eight (1 − (63/64)^8 ≈ 12%) has one, and rspamd then writes header.b="…".
     * The PR refuses the whole header at the quote: one genuine mail in eight (more with two signatures) is no longer
     * vouched, at random, for every customer.
     */
    public function test_rev_771_1_rspamd_quotes_a_dkim_signature_start_that_holds_a_slash(): void
    {
        $header = "mymail.myip.test;\r\n\tdkim=pass header.d=example.com header.s=dkim header.b=\"Qz8/Lm1p\";\r\n"
            . "\tdmarc=pass (policy=quarantine) header.from=example.com;\r\n\t" . self::SPF;

        $this->assertNotNull(AuthenticationResults::parse($header), 'a genuine rspamd header, refused whole for a quoted header.b');
        $this->assertSame('vouched: dmarc_pass', $this->verdict($header));

        // As a message, folded as rspamd folds it.
        $email = Parser::parse(Eml::make(["Authentication-Results: {$header}"]));
        $this->assertTrue(AuthenticationResults::verdict($email->authenticationResults, self::OURS, $email->fromEmail)->authenticated);
        $this->assertSame('not vouched: no_aligned_pass', $this->verdict($header, 'owner@victim.example'));
    }

    /**
     * REV-771-2 (false reject, high for senders that use them). An envelope sender with «=» or «/» in its local part
     * (VERP of mailing services «bounces+maria=example.com@…», SRS of forwarders «SRS0=…», BATV «prvs=…») is written
     * quoted by rspamd, in the SPF comment and as smtp.mailfrom. DKIM and DMARC passed for the From and are the
     * server's own statements; the PR refuses the header for the quotes of a value it does not even need.
     */
    public function test_rev_771_2_rspamd_quotes_an_envelope_sender_with_an_equals_sign(): void
    {
        foreach (['bounces+maria=example.com@em.mailer.example', 'SRS0=x9Kd=AB=example.com=maria@forwarder.example', 'prvs=0123456789=maria@example.com'] as $sender) {
            $header = "mymail.myip.test;\r\n\tdkim=pass header.d=example.com header.s=dkim header.b=Qz8kLm1p;\r\n"
                . "\tdmarc=pass (policy=none) header.from=example.com;\r\n"
                . "\tspf=pass (mymail.myip.test: domain of \"{$sender}\" designates 192.0.2.25 as permitted sender) smtp.mailfrom=\"{$sender}\"";

            $this->assertSame('vouched: dmarc_pass', $this->verdict($header), $sender);
        }
    }

    /**
     * REV-771-3 (false reject / what staff read, medium). For every DMARC result but pass and none, rspamd writes
     * reason="…" (it has spaces, so it is quoted). So each mail that honestly FAILS DMARC, the common forged mail, is
     * now «unreadable_results» in the quarantine instead of the true «no_aligned_pass»: «unreadable» no longer tells
     * staff an odd header from an everyday forgery. And a mail whose own DKIM passed for the From while the DMARC
     * lookup had a DNS error is refused, where DKIM alone vouches (as the class says: DMARC, or DKIM, or SPF).
     */
    public function test_rev_771_3_rspamd_writes_a_quoted_reason_when_dmarc_does_not_pass(): void
    {
        $failed = "mymail.myip.test;\r\n\tdkim=none;\r\n\tdmarc=fail reason=\"No valid SPF, No valid DKIM\" header.from=example.com (policy=reject);\r\n"
            . "\tspf=softfail (mymail.myip.test: 203.0.113.9 is neither permitted nor denied by domain of x@example.com) smtp.mailfrom=x@example.com";
        $this->assertSame('not vouched: no_aligned_pass', $this->verdict($failed), 'an honest DMARC fail reads, and says no');

        $dnsError = "mymail.myip.test;\r\n\tdkim=pass header.d=example.com header.s=dkim header.b=Qz8kLm1p;\r\n"
            . "\tdmarc=temperror reason=\"query timed out\" header.from=example.com (policy=temperror);\r\n\t" . self::SPF;
        $this->assertSame('vouched: dkim_pass', $this->verdict($dnsError));
    }

    /**
     * REV-771-5 (forgery, low: no server known to write it). The PR says a control character outside a comment is
     * refused, but a bare CR or LF (no folding: no space or tab after it) is first turned into a space, so one value
     * becomes two words, the first of them a pass for a domain of the sender's choosing. Only CRLF or LF followed by a
     * space or a tab is folding.
     */
    public function test_rev_771_5_a_bare_cr_or_lf_inside_a_value_is_a_control_character_not_a_space(): void
    {
        foreach (["\r", "\n"] as $bare) {
            $header = "mymail.myip.test; spf=pass smtp.mailfrom=example.com{$bare}smtp.x=@evil.test";

            $this->assertSame('not vouched: unreadable_results', $this->verdict($header), bin2hex($bare));
        }

        // The MIME parser hands a bare CR on as it is: the same through a whole message.
        $email = Parser::parse(Eml::make(["Authentication-Results: mymail.myip.test; spf=pass smtp.mailfrom=example.com\rsmtp.x=@evil.test"]));
        $this->assertFalse(AuthenticationResults::verdict($email->authenticationResults, self::OURS, $email->fromEmail)->authenticated);
    }

    /**
     * What a fix must keep (green today): once quoted values are read, a quoted value is ONE value, whatever it holds,
     * and an escaped quote does not end it. These are the envelope senders rspamd would quote.
     */
    public function test_a_quoted_value_stays_one_value_and_never_writes_results(): void
    {
        foreach ([
            'x; dkim=pass header.d=example.com; dmarc=pass header.from=example.com; y=z',
            'x) ; dkim=pass header.d=example.com ; y=z (',
            'x\\"; dkim=pass header.d=example.com; y=\\"z',
            'x" ; dkim=pass header.d=example.com ; y="z',
            'example.com x',
        ] as $local) {
            $sender = "\"{$local}@evil.test\"";
            $header = 'mymail.myip.test; dkim=pass header.d=evil.test header.s=s header.b=Qz8kLm1p; '
                . "spf=pass (mymail.myip.test: domain of {$sender} designates 203.0.113.9 as permitted sender) smtp.mailfrom={$sender}";

            $this->assertStringStartsWith('not vouched', $this->verdict($header), $local);
        }
    }

    /** Robustness (green today): a megabyte of header is read in linear time, and never as a pass. */
    public function test_a_megabyte_of_header_is_read_quickly(): void
    {
        $started = microtime(true);
        foreach ([str_repeat('a=b ', 250_000), str_repeat('(', 1_000_000), str_repeat('; x=y (c)', 110_000), str_repeat('a', 1_000_000) . '=', str_repeat("\t", 1_000_000)] as $junk) {
            $this->assertStringStartsWith('not vouched', $this->verdict('mymail.myip.test; dmarc=fail header.from=example.com; spf=pass ' . $junk));
        }
        $this->assertLessThan(5.0, microtime(true) - $started);
    }
}

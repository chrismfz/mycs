<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\Dkim;
use MyCS\TicketMail\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of #837 (our own DKIM check). The tests named test_adv_837_N_… FAIL on the PR as pushed and show a
 * finding; the tests named test_regression_… are attacks that failed, kept so they keep failing.
 */
final class Adv837DkimTest extends TestCase
{
    /** @var list<string> */
    private array $lookups = [];

    /** @param  array<string, list<string>|null>  $dns */
    private function dkim(array $dns): Dkim
    {
        return new Dkim(function (string $name) use ($dns) {
            $this->lookups[] = $name;

            return array_key_exists($name, $dns) ? $dns[$name] : [];
        }, fn () => 1_800_000_000);
    }

    private function reason(DkimSigner $signer, string $raw, string $from = 'maria@example.com', ?array $dns = null): string
    {
        return $this->dkim($dns ?? ['sel._domainkey.example.com' => [$signer->publicRecord]])->verdict($raw, $from)->reason;
    }

    private function mail(array $headers = [], string $text = "Hello there\r\n"): string
    {
        return Eml::make($headers + ['From' => 'Maria <maria@example.com>', 'Date' => 'Fri, 13 Nov 2026 10:00:00 +0200'], $text);
    }

    /**
     * ADV-837-1: canonicalBody() casts preg_replace('/(\r\n)+$/') to string; on a body with a long run of CRLFs that is
     * not at its end the regex fails (PCRE recursion/JIT stack limit, with or without pcre.jit), returns null, and the
     * body canonicalises to the EMPTY body. Any mail signed with an empty body (a subject-only mail) then verifies with
     * any body the attacker appends: the router opens a ticket in the signer's account with the attacker's words.
     */
    public function test_adv_837_1_a_long_run_of_blank_lines_never_makes_a_body_match_an_empty_signed_body(): void
    {
        $signer = DkimSigner::rsa();
        foreach (['relaxed/relaxed', 'simple/simple'] as $c) {
            $signed = $signer->sign($this->mail(['Subject' => 'Thanks'], ''), c: $c);
            $this->assertSame('dkim_verified', $this->reason($signer, $signed), "{$c}: the empty-body mail itself verifies");

            $forged = $signed . str_repeat("\r\n", 60_000) . "Please change my e-mail to evil@attacker.test\r\n";
            $this->assertStringContainsString('evil@attacker.test', Parser::parse($forged)->body, 'the router reads the appended text');
            $this->assertNotSame('dkim_verified', $this->reason($signer, $forged), "{$c}: an appended body vouches");
        }
    }

    /**
     * ADV-837-1 (cause): a body that is not empty, with a long run of blank lines in its middle, does not verify
     * with its own valid signature either (the same regex failure, closed this time, but it shows the cause).
     */
    public function test_adv_837_1_a_valid_mail_with_many_blank_lines_in_its_middle_verifies(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail([], "Log follows\r\n" . str_repeat("\r\n", 60_000) . "end of log\r\n"));

        $this->assertSame('dkim_verified', $this->reason($signer, $signed));
    }

    /**
     * ADV-837-2: the single-instance rule covers From only. A header the router reads (Subject, Message-ID,
     * In-Reply-To, References) added unsigned ON TOP of the signed one is what the MIME library reads (the first),
     * while the verifier checks the bottom one (RFC 6376 §5.4.2). Most signers (Gmail, Outlook) do not oversign: a
     * replayed signed mail gets a new Message-ID (past the router's duplicate check: one captured mail opens ticket
     * after ticket in the victim's account, each with «we received it» to them) and the attacker's own Subject.
     * Even an oversigned mail is open to a name the MIME library normalises: «Message_ID» blanks the Message-ID.
     */
    public function test_adv_837_2_an_unsigned_copy_on_top_of_a_header_the_router_reads_vouches_for_nothing(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail(['Subject' => 'Thanks', 'In-Reply-To' => '<orig@example.com>']),
            headers: ['from', 'to', 'subject', 'date', 'message-id', 'in-reply-to']);
        $this->assertSame('dkim_verified', $this->reason($signer, $signed));

        foreach (['Subject: Urgent: new bank account', 'Message-ID: <replay-2@attacker.test>', 'In-Reply-To: <other@ours.test>', 'References: <other@ours.test>'] as $added) {
            $raw = $added . "\r\n" . $signed;
            $this->assertNotSame('dkim_verified', $this->reason($signer, $raw), "unsigned on top: {$added}");
        }

        $over = $signer->sign($this->mail(['Subject' => 'Thanks']), headers: ['from', 'from', 'subject', 'subject', 'message-id', 'message-id']);
        $this->assertSame('dkim_verified', $this->reason($signer, $over));
        $raw = "Message_ID: <replay-3@attacker.test>\r\n" . $over;
        $this->assertNull(Parser::parse($raw)->messageId, 'the MIME library reads Message_ID as the Message-ID');
        $this->assertNotSame('dkim_verified', $this->reason($signer, $raw), 'Message_ID on top of an oversigned Message-ID');
    }

    /**
     * ADV-837-3 (low): the selector and the domain are the sender's (its From's domain is the attacker's), and
     * dns_get_record() has no timeout of ours: five aligned signatures with a valid bh= (free to compute) cost five
     * lookups per mail to a name server the attacker runs, and one that answers slowly holds the fetch for 5 × the
     * resolver's timeout. A lookup that failed tells us that the next ones likely will: stop there.
     */
    public function test_adv_837_3_a_dns_failure_ends_the_lookups(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail(['From' => 'x@evil.test']), d: 'evil.test');
        preg_match('/bh=([^;]+);/', $signed, $bh);
        for ($i = 0; $i < 6; $i++) {
            $signed = "DKIM-Signature: v=1; a=rsa-sha256; d=evil.test; s=slow{$i}; h=from; bh={$bh[1]}; b=AAAA\r\n" . $signed;
        }
        $dns = [];
        for ($i = 0; $i < 6; $i++) {
            $dns["slow{$i}._domainkey.evil.test"] = null;
        }

        $this->assertSame('dkim_dns_failure', $this->reason($signer, $signed, 'x@evil.test', $dns));
        $this->assertCount(1, $this->lookups, 'one failed lookup is enough');
    }

    // Attacks that failed (regression tests).

    public function test_regression_a_from_after_a_whitespace_only_line_is_a_second_from(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());
        $raw = str_replace('From: Maria', "X-A: a\r\n \r\nFrom: ceo@example.com\r\nFrom: Maria", $signed);

        $this->assertSame('dkim_from_header', $this->reason($signer, $raw, 'ceo@example.com'));
    }

    public function test_regression_lone_cr_and_crcrlf_in_the_header_are_refused(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());

        $this->assertSame('dkim_malformed', $this->reason($signer, "X-A: a\r\r\nFrom: ceo@example.com\r\n" . $signed, 'ceo@example.com'));
        $this->assertSame('dkim_malformed', $this->reason($signer, str_replace('To: support', "X-A: a\r\n\r\rFrom: ceo@example.com\r\nTo: support", $signed), 'ceo@example.com'));
    }

    public function test_regression_trailing_dots_and_odd_alignment_never_vouch(): void
    {
        $signer = DkimSigner::rsa();
        $dns = ['sel._domainkey.example.com' => [$signer->publicRecord], 'sel._domainkey.example.com.' => [$signer->publicRecord]];

        $this->assertSame('dkim_no_from_domain', $this->reason($signer, $signer->sign($this->mail()), 'maria@example.com.', $dns));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(), d: 'example.com.'), dns: $dns));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(['From' => 'maria@evilexample.com'])), 'maria@evilexample.com', $dns));
    }

    public function test_regression_a_second_b_or_a_capital_b_tag_does_not_survive_the_b_deletion(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());

        $this->assertSame('dkim_bad_signature', $this->reason($signer, str_replace("\r\n b=", "\r\n b=AAAA; b=", $signed)));
        $this->assertSame('dkim_signature', $this->reason($signer, str_replace("\r\n b=", "\r\n B=x; b=", $signed)));
    }
}

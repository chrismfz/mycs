<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\Dkim;
use PHPUnit\Framework\TestCase;

/** Our own DKIM check (docs/tickets.md §3 Β): a valid signature of the From's domain, or a parent of it, vouches. */
final class DkimTest extends TestCase
{
    /** @var list<string> the names looked up in DNS */
    private array $lookups = [];

    /** @param  array<string, list<string>|null>  $dns  name => TXT records (null: the lookup failed); unknown: none */
    private function dkim(array $dns, int $now = 1_800_000_000): Dkim
    {
        return new Dkim(function (string $name) use ($dns) {
            $this->lookups[] = $name;

            return array_key_exists($name, $dns) ? $dns[$name] : [];
        }, fn () => $now);
    }

    private function mail(array $headers = [], string $text = "Hello there\r\n"): string
    {
        return Eml::make($headers + ['From' => 'Maria <maria@example.com>', 'Date' => 'Fri, 13 Nov 2026 10:00:00 +0200'], $text);
    }

    private function reason(DkimSigner $signer, string $signed, string $from = 'maria@example.com', ?array $dns = null, int $now = 1_800_000_000): string
    {
        return $this->dkim($dns ?? ['sel._domainkey.example.com' => [$signer->publicRecord]], $now)->verdict($signed, $from)->reason;
    }

    public function test_rfc_8463s_example_verifies_with_each_of_its_keys(): void
    {
        // RFC 8463 Appendix A: one mail, signed with Ed25519 and with RSA, both relaxed/relaxed; LF line endings here.
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/dkim/rfc8463.eml');
        $ed = ['brisbane._domainkey.football.example.com' => ['v=DKIM1; k=ed25519; p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=']];
        $rsa = ['test._domainkey.football.example.com' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDkHlOQoBTzWRiGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQiY/J16JYi0Qvx/byYzCNb3W91y3FutACDfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqMKrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3Ip3G+2kryOTIKT+l/K4w3QIDAQAB']];

        $this->assertSame('dkim_verified', $this->dkim($ed)->verdict($raw, 'joe@football.example.com')->reason);
        $this->assertSame('dkim_verified', $this->dkim($rsa)->verdict($raw, 'joe@football.example.com')->reason);
        $this->assertSame('dkim_no_key', $this->dkim([])->verdict($raw, 'joe@football.example.com')->reason);
        $this->assertSame('dkim_body_hash', $this->dkim($ed)->verdict(str_replace('hungry', 'thirsty', $raw), 'joe@football.example.com')->reason);
    }

    public function test_a_mail_dkimpy_signed_simple_simple_verifies_with_a_parent_domain(): void
    {
        // Signed by dkimpy 1.1.8 (d=example.org, From maria@mail.example.org), CRLF, a folded Subject with spaces.
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/dkim/dkimpy-simple.eml');
        $dns = ['sel._domainkey.example.org' => ['v=DKIM1; k=rsa; p=' . trim((string) file_get_contents(__DIR__ . '/fixtures/dkim/dkimpy-simple.pub'))]];

        $this->assertTrue($this->dkim($dns)->verdict($raw, 'maria@mail.example.org')->authenticated);
        // Simple: one space more in a signed header, or at the end of a body line, is another mail.
        $this->assertSame('dkim_signature', $this->dkim($dns)->verdict(str_replace('Subject:   Help', 'Subject:    Help', $raw), 'maria@mail.example.org')->reason);
        $this->assertSame('dkim_body_hash', $this->dkim($dns)->verdict(str_replace("there \r\n", "there  \r\n", $raw), 'maria@mail.example.org')->reason);
    }

    public function test_rsa_and_ed25519_signatures_verify_in_every_canonicalization(): void
    {
        foreach ([DkimSigner::rsa(), DkimSigner::ed25519()] as $signer) {
            foreach (['relaxed/relaxed', 'simple/simple', 'relaxed/simple', 'simple/relaxed', 'relaxed'] as $c) {
                $signed = $signer->sign($this->mail(['Subject' => "Help \t me\r\n  please "], "Line  one \t\r\n\r\nTwo\r\n\r\n\r\n"), c: $c);
                $this->assertSame('dkim_verified', $this->reason($signer, $signed), "{$signer->algorithm} {$c}");
            }
        }
    }

    public function test_relaxed_tolerates_what_servers_on_the_way_change(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail([], "Hello  there\r\n"));

        // Whitespace, line endings stored as LF, a header the signature never named, another Received on top.
        $this->assertSame('dkim_verified', $this->reason($signer, str_replace('Subject: Help', "Subject:  Help \r\n ", $signed)));
        $this->assertSame('dkim_verified', $this->reason($signer, str_replace("\r\n", "\n", $signed)));
        $this->assertSame('dkim_verified', $this->reason($signer, "Received: from mx by us\r\nX-Spam: no\r\n" . $signed));
        $this->assertSame('dkim_verified', $this->reason($signer, str_replace("Hello  there\r\n", "Hello there \r\n\r\n\r\n", $signed)));
    }

    public function test_an_altered_body_or_signed_header_fails(): void
    {
        $signer = DkimSigner::ed25519();
        $signed = $signer->sign($this->mail());

        $this->assertSame('dkim_body_hash', $this->reason($signer, str_replace('Hello there', 'Pay to IBAN GR00', $signed)));
        $this->assertSame('dkim_body_hash', $this->reason($signer, $signed . "Appended\r\n"));
        $this->assertSame('dkim_signature', $this->reason($signer, str_replace('Subject: Help', 'Subject: Urgent', $signed)));
        $this->assertSame('dkim_signature', $this->reason($signer, str_replace('maria@example.com', 'eleni@example.com', $signed), 'eleni@example.com'));
        $this->assertSame('dkim_signature', $this->reason($signer, str_replace('Maria <', 'Billing <', $signed)));
        // A signed header the mail did not have, added later.
        $this->assertSame('dkim_signature', $this->reason($signer, str_replace("Subject: Help\r\n", "Subject: Help\r\nReply-To: evil@evil.test\r\n", $signer->sign($this->mail(), headers: ['from', 'subject', 'reply-to']))));
    }

    public function test_a_signature_by_another_key_or_a_forged_b_fails(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());

        $this->assertSame('dkim_signature', $this->reason($signer, $signed, dns: ['sel._domainkey.example.com' => [DkimSigner::rsa()->publicRecord]]));
        $this->assertSame('dkim_signature', $this->reason($signer, (string) preg_replace('/b=[A-Za-z0-9+\/]{4}/', 'b=AAAA', $signed, 1)));
    }

    public function test_one_from_only_and_only_by_its_name(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());

        // A From added on top (unsigned), or a header the MIME library reads as From.
        $this->assertSame('dkim_from_header', $this->reason($signer, "From: ceo@example.com\r\n" . $signed, 'ceo@example.com'));
        $this->assertSame('dkim_from_header', $this->reason($signer, "F-rom: ceo@example.com\r\n" . $signed, 'ceo@example.com'));
        $this->assertSame('dkim_from_header', $this->reason($signer, str_replace('From: ', 'FROM_: ', $signed)));
        // The signature must sign From.
        $this->assertSame('dkim_from_unsigned', $this->reason($signer, $signer->sign($this->mail(), headers: ['subject', 'date'])));
    }

    public function test_the_signing_domain_must_be_the_froms_or_a_parent_never_a_subdomain(): void
    {
        $signer = DkimSigner::rsa();
        $dns = fn (string $d) => ["sel._domainkey.{$d}" => [$signer->publicRecord]];

        $this->assertSame('dkim_verified', $this->reason($signer, $signer->sign($this->mail(), d: 'example.com'), 'maria@mail.example.com', $dns('example.com')));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(), d: 'evil.test'), dns: $dns('evil.test')));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(), d: 'mail.example.com'), dns: $dns('mail.example.com')));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(), d: 'com'), dns: $dns('com')));
        $this->assertSame('dkim_not_aligned', $this->reason($signer, $signer->sign($this->mail(), d: 'ample.com'), dns: $dns('ample.com')));
        $this->assertSame(['sel._domainkey.example.com'], $this->lookups, 'an unaligned signature is never looked up');
    }

    public function test_a_valid_signature_of_another_domain_beside_a_broken_one_of_ours_vouches_for_nothing(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($signer->sign($this->mail(), d: 'esp.test'), tags: ['bh' => base64_encode(str_repeat('x', 32))]);
        $dns = ['sel._domainkey.esp.test' => [$signer->publicRecord], 'sel._domainkey.example.com' => [$signer->publicRecord]];

        $this->assertSame('dkim_body_hash', $this->reason($signer, $signed, dns: $dns));
    }

    public function test_the_key_in_dns_fails_closed(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());
        $at = 'sel._domainkey.example.com';
        $p = substr($signer->publicRecord, (int) strpos($signer->publicRecord, 'p=') + 2);

        $this->assertSame('dkim_dns_failure', $this->reason($signer, $signed, dns: [$at => null]));
        $this->assertSame('dkim_no_key', $this->reason($signer, $signed, dns: []));
        $this->assertSame('dkim_key_ambiguous', $this->reason($signer, $signed, dns: [$at => [$signer->publicRecord, $signer->publicRecord]]));
        $this->assertSame('dkim_key_revoked', $this->reason($signer, $signed, dns: [$at => ['v=DKIM1; k=rsa; p=']]));
        $this->assertSame('dkim_key_testing', $this->reason($signer, $signed, dns: [$at => ["v=DKIM1; t=y; p={$p}"]]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ["v=DKIM1; k=ed25519; p={$p}"]]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ["v=DKIM1; h=sha1; p={$p}"]]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ["v=DKIM1; s=tlsrpt; p={$p}"]]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ['v=DKIM1; p=bm90IGEga2V5']]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ["v=spf1; p={$p}"]]));
        $this->assertSame('dkim_bad_key', $this->reason($signer, $signed, dns: [$at => ["v=DKIM1; p={$p}; p={$p}"]]));
        $this->assertSame('dkim_verified', $this->reason($signer, $signed, dns: [$at => ['k=rsa; t=s:x; h=sha1:sha256; s=email; p=' . chunk_split($p, 50, ' ')]]));
        // t=s: the i= domain must be d= itself.
        $this->assertSame('dkim_bad_signature', $this->reason($signer, $signer->sign($this->mail(), tags: ['i' => 'maria@mail.example.com']), dns: [$at => ["t=s; p={$p}"]]));
    }

    public function test_a_weak_rsa_key_is_refused(): void
    {
        $signer = @DkimSigner::rsa(512);
        $this->assertSame('dkim_weak_key', $this->reason($signer, $signer->sign($this->mail())));
    }

    public function test_expiry_and_the_signatures_own_tags(): void
    {
        $signer = DkimSigner::ed25519();
        $mail = $this->mail();

        $this->assertSame('dkim_expired', $this->reason($signer, $signer->sign($mail, tags: ['t' => '1700000000', 'x' => '1799999999'])));
        $this->assertSame('dkim_verified', $this->reason($signer, $signer->sign($mail, tags: ['t' => '1700000000', 'x' => '1800000000'])));
        $this->assertSame('dkim_expired', $this->reason($signer, $signer->sign($mail, tags: ['t' => '1900000000', 'x' => '1850000000'])), 'x= before t=');
        $this->assertSame('dkim_bad_signature', $this->reason($signer, $signer->sign($mail, tags: ['x' => 'soon'])));
        // l=: anyone may append to what it does not cover.
        $this->assertSame('dkim_length_tag', $this->reason($signer, $signer->sign($mail, tags: ['l' => '13'])));
        $this->assertSame('dkim_unsupported', $this->reason($signer, $signer->sign($mail, tags: ['a' => 'rsa-sha1'])));
        $this->assertSame('dkim_unsupported', $this->reason($signer, $signer->sign($mail, tags: ['v' => '2'])));
        $this->assertSame('dkim_unsupported', $this->reason($signer, $signer->sign($mail, tags: ['c' => 'nowsp/simple'])));
        $this->assertSame('dkim_unsupported', $this->reason($signer, $signer->sign($mail, tags: ['q' => 'https'])));
        $this->assertSame('dkim_verified', $this->reason($signer, $signer->sign($mail, tags: ['q' => 'dns/txt', 'i' => 'maria@mail.example.com'])));
        $this->assertSame('dkim_bad_signature', $this->reason($signer, $signer->sign($mail, tags: ['i' => 'maria@evil.test'])));
        $this->assertSame('dkim_bad_signature', $this->reason($signer, $signer->sign($mail, tags: ['i' => 'maria@notexample.com'])));
        $this->assertSame('dkim_bad_signature', $this->reason($signer, $signer->sign($mail, s: 'a/b')));
        // A tag twice, a name in capitals (tag names are case-sensitive), a tag list that does not read.
        $this->assertSame('dkim_bad_signature', $this->reason($signer, str_replace('DKIM-Signature: v=1;', 'DKIM-Signature: v=1; d=example.com;', $signer->sign($mail))));
        $this->assertSame('dkim_bad_signature', $this->reason($signer, str_replace(' bh=', ' BH=', $signer->sign($mail))));
        $this->assertSame('dkim_bad_signature', $this->reason($signer, str_replace('DKIM-Signature: v=1;', 'DKIM-Signature: v=1; junk;', $signer->sign($mail))));
    }

    public function test_no_signature_no_from_or_a_broken_header_vouch_for_nothing(): void
    {
        $signer = DkimSigner::rsa();
        $this->assertSame('dkim_none', $this->reason($signer, $this->mail()));
        $this->assertSame('dkim_none', $this->reason($signer, str_replace('DKIM-Signature:', 'X-DKIM-Signature:', $signer->sign($this->mail()))));
        $this->assertSame('dkim_no_from_domain', $this->reason($signer, $signer->sign($this->mail()), 'maria'));
        $this->assertSame('dkim_malformed', $this->reason($signer, " folded first\r\n" . $signer->sign($this->mail())));
        $this->assertSame('dkim_malformed', $this->reason($signer, "No colon here\r\n" . $signer->sign($this->mail())));
        $this->assertSame('dkim_malformed', $this->reason($signer, "From : maria@example.com\r\n" . $signer->sign($this->mail())));
        $this->assertSame('dkim_malformed', $this->reason($signer, "X-Note: a\rFrom: ceo@example.com\r\n" . $signer->sign($this->mail())));
        $this->assertSame([], $this->lookups);
    }

    public function test_at_most_five_aligned_signatures_are_looked_up(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());
        preg_match('/bh=([^;]+);/', $signed, $bh);
        for ($i = 0; $i < 7; $i++) {
            $signed = "DKIM-Signature: v=1; a=rsa-sha256; d=example.com; s=s{$i}; h=from; bh={$bh[1]}; b=AAAA\r\n" . $signed;
        }

        $this->assertSame('dkim_no_key', $this->reason($signer, $signed));
        $this->assertCount(Dkim::MAX_SIGNATURES, $this->lookups);
    }

    /** Review of #837: blank lines cost linear time (REV-837-2); a signed header twice is refused (REV-837-4). */
    public function test_blank_lines_are_cheap_and_a_signed_header_twice_vouches_for_nothing(): void
    {
        $signer = DkimSigner::rsa();
        $signed = $signer->sign($this->mail());
        $start = microtime(true);
        $this->assertSame('dkim_body_hash', $this->reason($signer, $signed . str_repeat("\r\n", 15_000) . "x\r\n" . str_repeat("\r\n", 200_000)));
        $this->assertLessThan(2.0, microtime(true) - $start);

        $this->assertSame('dkim_header_twice', $this->reason($signer, "To: someone@else.test\r\n" . $signed));
        $this->assertSame('dkim_header_twice', $this->reason($signer, "T_o: someone@else.test\r\n" . $signed));
        $this->assertSame('dkim_header_twice', $this->reason($signer, "Message_ID: <x@y.test>\r\n" . $signed));
        $this->assertSame('dkim_verified', $this->reason($signer, "Received: from a\r\nReceived: from b\r\n" . $signed), 'an unsigned header twice is no matter');
    }
}

<?php

namespace MyCS\BankAlerts\Tests;

use MyCS\BankAlerts\AlertParser;
use MyCS\BankAlerts\Amount;
use MyCS\BankAlerts\BankAlert;
use PHPUnit\Framework\TestCase;

final class AlertParserTest extends TestCase
{
    private const SERVER = 'mx.example.test';

    private const SEALER = 'example.test';

    private function parse(string $raw): ?BankAlert
    {
        return AlertParser::parse($raw, self::SERVER, self::SEALER);
    }

    public function test_a_eurobank_credit(): void
    {
        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '1.234,56', 'ΙΩΑΝΝΗΣ ΔΟΚΙΜΑΣΤΙΚΟΣ 32999', '8.151,51'));

        $this->assertNotNull($alert);
        $this->assertSame(['eurobank', 'credit', '1234.56', '8151.51', 'ΙΩΑΝΝΗΣ ΔΟΚΙΜΑΣΤΙΚΟΣ 32999'],
            [$alert->bank, $alert->direction, $alert->amount, $alert->balance, $alert->reason]);
        $this->assertTrue($alert->authentic, $alert->authenticity);
        $this->assertSame('2026-10-23 10:27', $alert->executedAt?->format('Y-m-d H:i'), 'the mail\'s date: Eurobank says no other');
        $this->assertNotNull($alert->messageId);
    }

    public function test_a_eurobank_debit_keeps_the_balance(): void
    {
        $alert = $this->parse(Fixtures::eurobank('χρεώθηκε', '1,20', 'COM POO TEST-0000', '8.032,47'));

        $this->assertSame(['debit', '1.20', '8032.47'], [$alert?->direction, $alert?->amount, $alert?->balance]);
    }

    public function test_a_piraeus_credit(): void
    {
        $alert = $this->parse(Fixtures::piraeus('100.44 EUR (ΠΙ)', 'ΑΤΜ-ΜΕΤΑΦ.ΑΠΟ ΛΟΓ.ΤΡΙΤΟΥ', 'ΜΑΡΙΑ ΠΑΡΑΔΕΙΓΜΑ', 'ΤΙΜΟΛΟΓΙΟ 32998', '2,903.01 EUR'));

        $this->assertSame(['piraeus', 'credit', '100.44', '2903.01', 'ΜΑΡΙΑ ΠΑΡΑΔΕΙΓΜΑ ΤΙΜΟΛΟΓΙΟ 32998'],
            [$alert?->bank, $alert?->direction, $alert?->amount, $alert?->balance, $alert?->reason]);
        $this->assertSame('2026-10-22 15:20', $alert->executedAt?->format('Y-m-d H:i'));
        $this->assertTrue($alert->authentic, $alert->authenticity);
    }

    public function test_a_piraeus_debit(): void
    {
        $alert = $this->parse(Fixtures::piraeus('-0.30 EUR (ΧΡ)', 'ΠΡΟΜΗΘΕΙΑ DIASDEBIT', 'Example S.A.', 'ABC123', '2636.37 EUR'));

        $this->assertSame(['debit', '0.30', '2636.37'], [$alert?->direction, $alert?->amount, $alert?->balance]);
    }

    public function test_another_sender_or_another_kind_of_mail_is_no_alert(): void
    {
        $this->assertNull($this->parse(str_replace('eurobank@eurobank.gr', 'alerts@eurobank.example', Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00'))));
        $notice = "From: eurobank@eurobank.gr\r\nSubject: News\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nΝέα προϊόντα για εσάς.\r\n";
        $this->assertNull($this->parse($notice));
        $this->assertNull($this->parse('not a mail at all'));
    }

    /** The banks' other mails (newsletters, notices, reminders, a password changed): no movement, whoever vouches for them. */
    public function test_a_banks_other_mails_are_no_alerts(): void
    {
        $auth = Fixtures::forwardedByUs('eurobank.gr');
        foreach (['Νέα προϊόντα για εσάς: δάνεια με χαμηλό επιτόκιο.', 'Ο κωδικός πρόσβασής σας στο e-Banking άλλαξε.',
            'Υπενθύμιση: η κάρτα σας λήγει. Σας ενημερώνουμε ότι η συνδρομή σας ανανεώθηκε.',
            'Σας ενημερώνουμε ότι πιστώθηκε με επιτυχία η αίτησή σας.'] as $text) {
            $raw = $auth . "From: eurobank@eurobank.gr\r\nSubject: Eurobank\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$text}\r\n";
            $this->assertNull($this->parse($raw), $text);
        }
        $piraeus = Fixtures::forwardedByUs('piraeusbank.gr') . "From: Piraeus Alerts <IBankV2Email@piraeusbank.gr>\r\nSubject: Ενημέρωση\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n<html><body><p>Λογαριασμός: ΟΨΕΩΣ</p><p>Νέοι όροι χρήσης.</p></body></html>\r\n";
        $this->assertNull($this->parse($piraeus));
    }

    public function test_the_account_the_alert_is_about(): void
    {
        $this->assertSame('0000', $this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00'))?->account);
        $this->assertSame('ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE', $this->parse(Fixtures::piraeus('1.00 EUR (ΠΙ)', 'T', 'A', 'B', '1.00 EUR'))?->account);
    }

    public function test_a_mail_our_server_does_not_vouch_for_is_not_authentic(): void
    {
        $direct = "Authentication-Results: mx.example.test; dmarc=fail header.from=eurobank.gr; spf=fail smtp.mailfrom=eurobank.gr\r\n";
        $this->assertFalse($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $direct))?->authentic);

        $none = '';
        $this->assertFalse($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $none))?->authentic);

        $failedUpstream = Fixtures::forwardedByUs('eurobank.gr', 'fail');
        $this->assertFalse($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $failedUpstream))?->authentic);
    }

    public function test_forged_arc_headers_are_not_believed(): void
    {
        // Another sealer's first set, saying what we would say: the chain may pass, but i=1 is not ours.
        $theirs = str_replace('d=example.test;', 'd=attacker.example;', Fixtures::forwardedByUs('eurobank.gr'));
        $alert = $this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $theirs));
        $this->assertFalse($alert?->authentic);
        $this->assertSame('arc_seal_not_ours', $alert?->authenticity);

        // Our topmost result does not say arc=pass (a forged set breaks the chain): a «pass» below it counts for nothing.
        $broken = "Authentication-Results: mx.example.test; arc=fail\r\nAuthentication-Results: mx.example.test; dmarc=pass header.from=eurobank.gr\r\n"
            . substr(Fixtures::forwardedByUs('eurobank.gr'), strpos(Fixtures::forwardedByUs('eurobank.gr'), 'ARC-Seal'));
        $this->assertFalse($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $broken))?->authentic);

        // Two first sets: never chosen between.
        $two = Fixtures::forwardedByUs('eurobank.gr') . "ARC-Authentication-Results: i=1; mx.example.test; dmarc=pass header.from=eurobank.gr\r\n";
        $this->assertFalse($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $two))?->authentic);
    }

    public function test_a_mail_delivered_straight_to_the_mailbox(): void
    {
        $direct = "Authentication-Results: mx.example.test; dkim=pass header.d=eurobank.gr; dmarc=pass header.from=eurobank.gr\r\n";

        $this->assertTrue($this->parse(Fixtures::eurobank('πιστώθηκε', '10,00', 'X', '1,00', $direct))?->authentic);
    }

    public function test_amounts(): void
    {
        $this->assertSame('1234567.89', Amount::parse('1.234.567,89', ','));
        $this->assertSame('5.00', Amount::parse('5', ','));
        $this->assertSame('0.50', Amount::parse('0,5', ','));
        $this->assertSame('-743.94', Amount::parse('-743.94', '.'));
        $this->assertSame('2085.68', Amount::parse('2,085.68', '.'));
        $this->assertNull(Amount::parse('12,34,56', ','));
        $this->assertNull(Amount::parse('abc', '.'));
    }
}

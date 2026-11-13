<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\MailHeaders;
use PHPUnit\Framework\TestCase;

/** Adversarial review of #846: the «Server» line must never show a host or address the sender chose. */
final class Adv846MailHeadersTest extends TestCase
{
    /**
     * ADV-846-1. Exim (cPanel, orion) with no verified reverse name writes «from [ip] (port=N helo=X)». The HELO is the
     * sender's: a HELO address literal must not win over the real one.
     */
    public function test_exim_without_reverse_name_shows_the_connecting_address_not_the_helo_literal(): void
    {
        $raw = Eml::make([
            'Received: from [203.0.113.5] (port=51234 helo=[198.51.100.66]) by orion.example with esmtps (TLS1.3) (Exim 4.98)',
        ]);

        $server = (string) MailHeaders::sendingServer($raw);
        $this->assertStringContainsString('203.0.113.5', $server);
        $this->assertStringNotContainsString('198.51.100.66', $server);
    }

    /**
     * ADV-846-2. Postfix with no (forward-confirmed) reverse name writes «from HELO (unknown [ip])»: the host shown is
     * then the HELO the sender typed (here a bank's), which reads like a verified name.
     */
    public function test_postfix_unknown_reverse_name_does_not_show_the_helo_as_the_host(): void
    {
        $raw = Eml::make([
            'Received: from mail.bank.example (unknown [203.0.113.5]) by mx.ours.test (Postfix) with ESMTPS id abc',
        ]);

        $this->assertStringNotContainsString('mail.bank.example', (string) MailHeaders::sendingServer($raw));
    }

    /**
     * ADV-846-3. Our own topmost line names no address (Exim's local submission on a shared cPanel host, «from user by
     * orion with local»): every Received below it is the sender's, so none of them is the server.
     */
    public function test_a_forged_received_under_our_local_submission_line_is_not_the_server(): void
    {
        $raw = Eml::make([
            'Received: from someuser by orion.example with local (Exim 4.98) (envelope-from <someuser@orion.example>) id 1abc',
            'Received: from mail.bank.example (mail.bank.example [192.0.2.44]) by mx.bank.example with ESMTPS',
        ]);

        $server = MailHeaders::sendingServer($raw);
        $this->assertTrue($server === null || !str_contains($server, '192.0.2.44'), "Forged server shown: {$server}");
    }
}

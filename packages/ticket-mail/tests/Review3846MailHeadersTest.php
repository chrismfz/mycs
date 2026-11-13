<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\MailHeaders;
use PHPUnit\Framework\TestCase;

/** Review 3 of #846: our delivery agent (Dovecot LMTP) adds its own line on top, on cPanel and on mailcow. */
final class Review3846MailHeadersTest extends TestCase
{
    public function test_cpanel_lmtp_line_with_no_address_is_passed_over(): void
    {
        // As on orion: Dovecot's line (no address), then exim's.
        $raw = Eml::make([
            "Received: from orion.example\r\n\tby orion.example with LMTP\r\n\tid kIAO\r\n\t(envelope-from <a@example.gr>)",
            "Received: from mail.example.gr ([203.0.113.25]:54321)\r\n\tby orion.example with esmtps (TLS1.3) (Exim 4.98)",
            'Received: from forged.example (forged.example [198.51.100.9]) by mail.example.gr',
        ]);

        $this->assertSame('mail.example.gr [203.0.113.25]', MailHeaders::sendingServer($raw));
    }

    public function test_mailcow_lmtps_line_from_the_docker_network_is_passed_over(): void
    {
        $raw = Eml::make([
            "Received: from mymail.myip.test ([172.22.1.253])\r\n\tby mymail.myip.test with LMTPS id X",
            "Received: from mail.example.gr (mail.example.gr [203.0.113.25])\r\n\tby mymail.myip.test (Postfix) with ESMTPS id Y",
        ]);

        $this->assertSame('mail.example.gr [203.0.113.25]', MailHeaders::sendingServer($raw));
    }

    public function test_a_local_submission_under_lmtp_still_gives_none(): void
    {
        $raw = Eml::make([
            'Received: from orion.example by orion.example with LMTP id Z',
            'Received: from someuser by orion.example with local (Exim 4.98) id W',
            'Received: from forged.example (forged.example [198.51.100.9]) by x',
        ]);

        $this->assertNull(MailHeaders::sendingServer($raw));
    }
}

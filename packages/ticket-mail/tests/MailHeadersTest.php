<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\MailHeaders;
use PHPUnit\Framework\TestCase;

final class MailHeadersTest extends TestCase
{
    public function test_the_server_is_the_one_our_server_recorded(): void
    {
        $raw = Eml::make([
            "Received: from localhost (localhost [127.0.0.1])\r\n\tby mx.ours.test with LMTP",
            "Received: from mail.example.gr (mail.example.gr [203.0.113.25])\r\n\tby mx.ours.test with ESMTPS id abc",
            'Received: from forged.test (forged.test [192.0.2.1]) by mail.example.gr',
        ]);

        $this->assertSame('mail.example.gr [203.0.113.25]', MailHeaders::sendingServer($raw));
    }

    public function test_exim_style_and_bare_addresses(): void
    {
        $this->assertSame('helo.example.org [198.51.100.7]', MailHeaders::sendingServer(Eml::make([
            'Received: from helo.example.org ([198.51.100.7]:41234 helo=helo.example.org) by orion with esmtps'])));
        $this->assertSame('[2001:db8::1]', MailHeaders::sendingServer(Eml::make([
            'Received: from [IPv6:2001:db8::1] by mx.ours.test'])));
        $this->assertNull(MailHeaders::sendingServer(Eml::make(['Received: by mx.ours.test with local'])));
        $this->assertNull(MailHeaders::sendingServer(Eml::make()));
    }

    public function test_the_block_stops_at_the_body_and_is_capped(): void
    {
        $block = MailHeaders::block(Eml::make([], 'Secret body'));
        $this->assertStringContainsString('From: Maria <maria@example.com>', $block);
        $this->assertStringNotContainsString('Secret body', $block);
        $this->assertStringNotContainsString("\r", $block);

        $long = MailHeaders::block(Eml::make(['X-Long' => str_repeat('a', MailHeaders::MAX_BYTES)]));
        $this->assertSame(MailHeaders::MAX_BYTES, strlen($long));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TicketDepartment;
use App\Support\Tickets\InboundRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\MailHeaders;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\TestCase;

class Review846MailDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mail_with_raw_8bit_header_bytes_still_opens_a_ticket(): void
    {
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $dep = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $dep->update(['email' => 'support@myip.test', 'authserv_id' => 'mx.myip.test']);
        // ISO-8859-7 «Βοήθεια» sent unencoded, as old clients and spam do.
        $raw = Eml::make(['Subject' => "\xC2\xEF\xDE\xE8\xE5\xE9\xE1", 'X-Mailer' => "Outlook \xE1"], 'Site down');
        $outcome = app(InboundRouter::class)->route($dep, Parser::parse($raw), $raw);
        $this->assertNotNull($outcome->ticket);
        $this->assertIsArray($outcome->ticket->messages()->sole()->mail);
    }

    public function test_block_is_valid_utf8(): void
    {
        $this->assertTrue(mb_check_encoding(MailHeaders::block("Subject: \xE1\r\n\r\nx"), 'UTF-8'));
    }

    public function test_received_forms(): void
    {
        $f = fn (string $r) => MailHeaders::sendingServer("Received: {$r}\nReceived: from forged.example (forged.example [198.51.100.9]) by x\n\nbody");
        // Exim (cPanel)
        $this->assertSame('mail.example.gr [203.0.113.25]', $f('from mail.example.gr ([203.0.113.25]:54321 helo=mail.example.gr) by mx.ours with esmtps'));
        // Postfix, no rDNS
        $this->assertSame('[203.0.113.25]', $f('from helo.example (unknown [203.0.113.25]) by mx.ours'));
        // CRLF + folded
        $this->assertSame('mail.example.gr [203.0.113.25]', MailHeaders::sendingServer("Received: from mail.example.gr\r\n\t(mail.example.gr [203.0.113.25])\r\n by mx\r\n\r\nb"));
        // IPv4-mapped loopback / private relay (amavis, LMTP hop) falls through to the next line

        $this->assertSame('forged.example [198.51.100.9]', $f('from mx.ours (mx.ours [127.0.0.2]) by mx'));
    }
}

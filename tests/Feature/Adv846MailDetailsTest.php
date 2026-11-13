<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\QuarantineActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\TestCase;

/** Adversarial review of #846: the stored mail details must never stop a mail from becoming a ticket. */
class Adv846MailDetailsTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'authserv_id' => 'mx.myip.test']);
    }

    /**
     * ADV-846-4. Raw 8-bit headers that are not UTF-8 (an old Greek client's ISO-8859-7 X-Mailer or Subject, or anyone
     * who wants to) make json_encode of the `mail` cast fail: the whole mail is refused, not only its details.
     */
    public function test_a_mail_with_non_utf8_raw_header_bytes_still_opens_a_ticket(): void
    {
        $raw = Eml::make([
            'Received: from mail.example.com (mail.example.com [203.0.113.25]) by mx.myip.test with ESMTPS',
            'X-Mailer' => "Outlook \xE2\xEF\xDE\xE8\xE5\xE9\xE1",
        ], 'Site down');

        $outcome = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw);

        $this->assertInstanceOf(Ticket::class, $outcome->ticket);
        $mail = $outcome->ticket->messages()->sole()->mail;
        $this->assertIsArray($mail);
        $this->assertStringContainsString('X-Mailer: Outlook', $mail['headers']);
    }

    /** ADV-846-4b. The same through a 32KB cut that lands inside a byte run that is not UTF-8. */
    public function test_a_long_header_block_with_8bit_bytes_still_opens_a_ticket(): void
    {
        $raw = Eml::make([
            'Received: from mail.example.com (mail.example.com [203.0.113.25]) by mx.myip.test with ESMTPS',
            'X-Pad' => str_repeat("\xE1", 40_000),
        ], 'Site down');

        $outcome = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw);

        $this->assertInstanceOf(Ticket::class, $outcome->ticket);
        $this->assertIsArray($outcome->ticket->messages()->sole()->mail);
    }

    /** ADV-846-4c. Quarantined after its failed tries, it cannot be opened by staff either: the same encode fails. */
    public function test_the_failed_mail_can_still_be_opened_from_the_quarantine(): void
    {
        $raw = Eml::make(['From' => 'eve@example.com', 'List-Id' => '<x>', 'Message-ID' => '<q846@example.com>',
            'X-Mailer' => "Outlook \xE2\xEF\xDE\xE8"]);
        $quarantine = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw)->quarantine;

        $ticket = app(QuarantineActions::class)->open($quarantine, Staff::factory()->admin()->create());

        $this->assertIsArray($ticket->messages()->sole()->mail);
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\QuarantineActions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\TestCase;

/**
 * What staff read of a mail beside its message (owner 2026-11-13: «is it legit?»): its From, the server our server took
 * it from, our server's verdict and the header block; the thread shows each message's full time and channel.
 */
class TicketMailDetailsTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function route(string $raw): Ticket
    {
        return app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw)->ticket;
    }

    public function test_a_vouched_mail_keeps_its_from_server_and_verdict_and_the_page_shows_them(): void
    {
        $this->freezeTime();
        $ticket = $this->route(Eml::make([
            'Received: from mail.example.com (mail.example.com [203.0.113.25]) by mx.myip.test with ESMTPS',
            'Authentication-Results: mx.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com',
        ], 'Site down'));

        $mail = $ticket->messages()->sole()->mail;
        $this->assertSame('Maria <maria@example.com>', $mail['from']);
        $this->assertSame('mail.example.com [203.0.113.25]', $mail['server']);
        $this->assertTrue($mail['vouched']);
        $this->assertStringContainsString('Authentication-Results: mx.myip.test', $mail['headers']);
        $this->assertStringNotContainsString('Site down', $mail['headers']);

        $this->actingAs(Staff::factory()->admin()->create(), 'staff')
            ->get(EditTicket::getUrl(['record' => $ticket]))->assertOk()
            ->assertSee('mail.example.com [203.0.113.25]')
            ->assertSee(__('Verified'))
            ->assertSee(now()->format('d/m/Y H:i'))
            ->assertSee(__('The whole mail and its headers'));
    }

    public function test_an_unvouched_one_says_so_and_why(): void
    {
        $ticket = $this->route(Eml::make(['Received: from forged.test (forged.test [198.51.100.7]) by mx.myip.test']));

        $mail = $ticket->messages()->sole()->mail;
        $this->assertFalse($mail['vouched']);
        $this->assertNotSame('', $mail['reason']);
        $this->actingAs(Staff::factory()->admin()->create(), 'staff')
            ->get(EditTicket::getUrl(['record' => $ticket]))->assertOk()
            ->assertSee(__('Not verified: it may be forged'))
            ->assertSee('forged.test [198.51.100.7]');
    }

    public function test_one_released_from_the_quarantine_keeps_them_too(): void
    {
        $raw = Eml::make(['From' => 'eve@example.com', 'List-Id' => '<x>', 'Message-ID' => '<q1@example.com>',
            'Received: from mx.example.com (mx.example.com [192.0.2.9]) by mx.myip.test']);
        $quarantine = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw)->quarantine;

        $ticket = app(QuarantineActions::class)->open($quarantine, Staff::factory()->admin()->create());

        $mail = $ticket->messages()->sole()->mail;
        $this->assertSame('eve@example.com', $mail['from']);
        $this->assertSame('mx.example.com [192.0.2.9]', $mail['server']);
    }

    public function test_messages_without_mail_details_still_show(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Old', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'From before', 'via' => TicketMessage::VIA_EMAIL, 'body_original' => 'From before']);

        $this->actingAs(Staff::factory()->admin()->create(), 'staff')
            ->get(EditTicket::getUrl(['record' => $ticket]))->assertOk()
            ->assertSee('From before')
            ->assertSee(__('By email: the whole mail'))
            ->assertDontSee(__('Not verified: it may be forged'));
    }
}

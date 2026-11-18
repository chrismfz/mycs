<?php

namespace Tests\Feature;

use App\Livewire\Client\Emails;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\Import\WhmcsEmailHistoryImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review of #911 (security-audit mediums): «My emails» hides from a member with «emails» alone the kinds of areas not
 * granted, by EmailKinds::KINDS. Rows whose template key is not in KINDS fall to «general» and stay visible.
 */
class Rev911EmailsKindsBypassTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $owner->clients()->firstOrFail();
        $this->member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $this->member->id, 'owner' => false,
            'permissions' => ['emails'], 'granted_at' => now()]);
        $this->actingAs($this->member)->withSession($this->loginUser($this->member));
    }

    private function mail(string $subject, string $key): EmailLog
    {
        return EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => $subject, 'to' => 'owner@example.test',
            'body' => "<p>{$subject} body</p>", 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'template_key' => $key]);
    }

    /** A year of WHMCS's mail (invoices, tickets, welcome mails) is imported under one key, «whmcs_history». */
    public function test_imported_whmcs_invoice_mail_is_hidden_from_a_member_without_invoices(): void
    {
        $this->mail('Customer Invoice #32001 (WHMCS)', WhmcsEmailHistoryImport::TEMPLATE_KEY);

        Livewire::actingAs($this->member)->test(Emails::class)->assertDontSee('Customer Invoice #32001');
    }

    /** A staff reply copied to a ticket's CC (TicketMailer::copies) is logged on the account as «ticket_cc_message». */
    public function test_a_ticket_reply_copied_to_a_cc_is_hidden_from_a_member_without_tickets(): void
    {
        $this->mail('[Ticket #77] Re: your server', 'ticket_cc_message');
        $this->mail('[Ticket #78] We received your message', 'ticket_received');

        Livewire::actingAs($this->member)->test(Emails::class)->assertDontSee('[Ticket #77]')->assertDontSee('[Ticket #78]');
    }
}

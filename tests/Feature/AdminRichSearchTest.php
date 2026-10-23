<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin search, as WHMCS's (owner 2026-10-23): each result with its status and whose it is, on a line or two. */
class AdminRichSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $this->owner = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Pappa']);
    }

    /** @return list<array{title: string, details: list<string>}> */
    private function results(string $resource, string $search): array
    {
        return $resource::getGlobalSearchResults($search)->map(fn ($result) => [
            'title' => html_entity_decode(strip_tags((string) (is_string($result->title) ? e($result->title) : $result->title->toHtml())), ENT_QUOTES),
            'details' => array_map('strval', $result->details),
        ])->values()->all();
    }

    public function test_two_domains_of_one_name_are_told_apart_by_status_with_their_client_and_expiry(): void
    {
        $client = $this->owner->clients()->firstOrFail();
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr', 'expires_at' => '2027-03-05']);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr', 'status' => DomainStatus::TransferredAway]);

        $results = $this->results(DomainResource::class, 'cdnet.gr');

        $this->assertCount(2, $results);
        $titles = array_column($results, 'title');
        $this->assertStringContainsString('cdnet.gr', $titles[0]);
        $this->assertStringContainsString(DomainStatus::Active->getLabel(), implode('|', $titles));
        $this->assertStringContainsString(DomainStatus::TransferredAway->getLabel(), implode('|', $titles));
        $this->assertSame('Maria Pappa #' . $client->id, $results[0]['details'][__('Client')]);
        $this->assertContains('05/03/2027', array_column(array_column($results, 'details'), __('Expires')));
    }

    public function test_a_service_ticket_and_invoice_show_their_status(): void
    {
        $client = $this->owner->clients()->firstOrFail();
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'suspended', 'domain' => 'shop.example.test', 'currency_code' => 'USD', 'price' => 10]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Mail is down', 'status' => Ticket::STATUS_OPEN]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'due_at' => '2026-11-01']);

        $this->assertStringContainsString(Service::statusLabel('suspended'), $this->results(ServiceResource::class, 'shop.example.test')[0]['title']);
        $found = $this->results(TicketResource::class, 'Mail is down')[0];
        $this->assertStringContainsString(Ticket::statusLabel(Ticket::STATUS_OPEN), $found['title']);
        $this->assertStringContainsString('#' . $ticket->reference, $found['title']);
        $this->assertSame('Maria Pappa #' . $client->id, $found['details'][__('Client')]);
        $this->assertNotEmpty($this->results(TicketResource::class, (string) $ticket->reference));

        $byNumber = $this->results(InvoiceResource::class, (string) ($invoice->number ?: $invoice->id));
        $this->assertNotEmpty($byNumber, 'an invoice is found by its number');
        $this->assertStringContainsString(Invoice::statusLabel('unpaid'), $byNumber[0]['title']);
        $this->assertSame('01/11/2026', $byNumber[0]['details'][__('Due Date')]);
    }

    public function test_a_name_is_escaped_in_the_title(): void
    {
        Ticket::factory()->create(['client_id' => $this->owner->clients()->firstOrFail()->id, 'subject' => '<img src=x onerror=alert(1)>', 'status' => Ticket::STATUS_OPEN]);

        $html = TicketResource::getGlobalSearchResults('onerror')->first()->title->toHtml();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_the_whole_search_renders_with_them(): void
    {
        $client = $this->owner->clients()->firstOrFail();
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr']);

        $results = Filament::getGlobalSearchProvider()->getResults('cdnet');

        $this->assertNotNull($results);
        $this->assertNotEmpty($results->getCategories());
    }
}

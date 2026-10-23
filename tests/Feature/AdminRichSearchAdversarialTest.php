<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\TicketResource;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of PR #380 (rich admin search). */
class AdminRichSearchAdversarialTest extends TestCase
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

    private function queriesFor(string $resource, string $search): int
    {
        $resource::getGlobalSearchResults($search); // warm up (settings, caches)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resource::getGlobalSearchResults($search)->each(fn ($r) => [$r->title instanceof Htmlable ? $r->title->toHtml() : $r->title, $r->details]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** One query per invoice for its adjustment notes (total -> adjustmentNotes is not eager loaded). */
    public function test_invoice_results_do_not_query_once_per_invoice(): void
    {
        $clients = collect(range(1, 25))->map(fn () => User::factory()->create()->clients()->firstOrFail());
        foreach ($clients as $client) {
            Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'due_at' => '2026-11-01']);
        }
        $few = $this->queriesFor(InvoiceResource::class, (string) Invoice::query()->min('id'));
        $many = $this->queriesFor(InvoiceResource::class, '');

        $this->assertLessThan(15, $many, "25 invoices take {$many} queries (one result: {$few})");
    }

    public function test_domain_ticket_client_results_do_not_query_once_per_row(): void
    {
        foreach (range(1, 25) as $i) {
            $client = User::factory()->create()->clients()->firstOrFail();
            Domain::factory()->create(['client_id' => $client->id, 'sld' => "nplus{$i}", 'tld' => 'gr', 'fqdn' => "nplus{$i}.gr"]);
            Ticket::factory()->create(['client_id' => $client->id, 'subject' => "nplus {$i}", 'status' => Ticket::STATUS_OPEN]);
        }

        foreach ([DomainResource::class, TicketResource::class, ClientResource::class] as $resource) {
            $n = $this->queriesFor($resource, $resource === ClientResource::class ? '' : 'nplus');
            $this->assertLessThan(15, $n, "{$resource}: {$n} queries for 25 results");
        }
    }

    public function test_searching_letters_or_symbols_on_the_id_columns_does_not_error(): void
    {
        Invoice::factory()->create(['client_id' => $this->owner->clients()->firstOrFail()->id]);
        Ticket::factory()->create(['client_id' => $this->owner->clients()->firstOrFail()->id, 'subject' => 'x']);

        foreach (['abc', "o'brien", '%', '_', '2026-'] as $q) {
            $this->assertIsIterable(InvoiceResource::getGlobalSearchResults($q));
            $this->assertIsIterable(TicketResource::getGlobalSearchResults($q));
        }
    }

    /**
     * Filament's view keeps `$isAssoc ??= Arr::isAssoc($result->details)` across ALL results: the first result with
     * details decides for every other. Services give labelled details ('Client' => …), the new ones give lists, so a
     * list after a service gets labels «0:», «1:» (or the service loses its labels when a list comes first).
     */
    public function test_list_details_after_a_service_are_not_labelled_with_their_index(): void
    {
        $client = $this->owner->clients()->firstOrFail();
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'domain' => 'zzmix.gr', 'currency_code' => 'USD', 'price' => 10]);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'zzmix', 'tld' => 'gr', 'fqdn' => 'zzmix.gr']);

        $html = Livewire::test(GlobalSearch::class)->set('search', 'zzmix')->html();

        $this->assertStringContainsString('zzmix.gr', $html);
        $labels = [];
        preg_match_all('/fi-global-search-result-detail-label"\s*>\s*([^<]*?)\s*<\/dt>/', $html, $labels);
        $this->assertNotContains('0:', $labels[1], 'a list detail is labelled with its index');
        $this->assertContains('Client:', $labels[1], 'the service keeps its labels');
    }

    /** The other way round: a ticket listed after a service gets «0:» and «1:» as labels of its details. */
    public function test_a_ticket_after_a_service_is_not_labelled_0_and_1(): void
    {
        $client = $this->owner->clients()->firstOrFail();
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'domain' => 'qqmix.gr', 'currency_code' => 'USD', 'price' => 10]);
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'qqmix.gr is down', 'status' => Ticket::STATUS_OPEN]);

        $html = Livewire::test(GlobalSearch::class)->set('search', 'qqmix')->html();

        $this->assertStringContainsString('qqmix.gr is down', $html);
        $labels = [];
        preg_match_all('/fi-global-search-result-detail-label"\s*>\s*([^<]*?)\s*<\/dt>/', $html, $labels);
        $this->assertNotContains('0:', $labels[1], 'a ticket detail is labelled with its index');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Components\QuickSearch;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quick search (P4d, owner 2026-10-19): Ctrl+K finds the account's domains, services, invoices and tickets; never another
 * account's, never a draft, each area within the member's grant.
 */
class QuickSearchTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user, ?Client $client = null): void
    {
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => ($client ?? $user->client())->id]));
    }

    /** @return list<string> the titles found */
    private function search(string $q): array
    {
        return array_column(Livewire::test(QuickSearch::class)->set('q', $q)->instance()->results(), 'title');
    }

    /** One of each kind, all named «shop», on $client. */
    private function shop(Client $client, string $tag): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => "shop{$tag}", 'tld' => 'gr', 'fqdn' => "shop{$tag}.gr",
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active]);
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'label' => "Shop hosting {$tag}"]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID]);
        Invoice::query()->whereKey($invoice->id)->update(['number' => "SHOP-{$tag}"]); // the numbering sets its own on create
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => "Shop is slow {$tag}", 'status' => 'open']);
    }

    public function test_it_finds_the_accounts_own_of_every_kind_and_nothing_of_another(): void
    {
        $user = User::factory()->create();
        $this->shop($user->client(), 'mine');
        $this->shop(User::factory()->create()->client(), 'theirs');
        $this->as($user);

        $found = $this->search('SHOP');
        $this->assertContains('shopmine.gr', $found);
        $this->assertContains('Shop hosting mine', $found);
        $this->assertContains(__('invoices.invoice', ['id' => 'SHOP-mine']), $found);
        $this->assertContains('#' . Ticket::query()->where('subject', 'Shop is slow mine')->value('id') . ' · Shop is slow mine', $found);
        foreach ($found as $title) {
            $this->assertStringNotContainsString('theirs', $title);
        }
    }

    public function test_a_draft_a_short_term_and_wildcards_find_nothing(): void
    {
        $user = User::factory()->create();
        $this->shop($user->client(), 'mine');
        $draft = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID]);
        Invoice::query()->whereKey($draft->id)->update(['status' => Invoice::STATUS_DRAFT, 'number' => 'DRAFT-77']);
        $this->as($user);

        $this->assertSame([], $this->search('DRAFT-77'));
        $this->assertSame([], $this->search('s'));
        $this->assertSame([], $this->search('%%'));
        $this->assertSame([], $this->search('__'));
    }

    public function test_an_invoice_or_ticket_is_found_by_its_number(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $user->client()->id, 'subject' => 'Mail bounce', 'status' => 'replied']);
        $this->as($user);

        $this->assertContains('#' . $ticket->id . ' · Mail bounce', $this->search('#' . $ticket->id));
    }

    public function test_a_member_finds_only_the_areas_of_their_grant(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->shop($client, 'mine');
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);
        $this->as($member, $client);

        $this->assertSame(['shopmine.gr'], $this->search('shop'));
    }

    public function test_the_header_has_it_for_a_customer(): void
    {
        $user = User::factory()->create();
        $this->as($user);

        $this->get(route('dashboard'))->assertOk()->assertSee(__('search.open'));
    }
}

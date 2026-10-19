<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4a of the portal (owner 2026-10-19): a customer's main menu is their own pages, one click each, the shop in one «New
 * order» menu; every page has its way back from Home (breadcrumbs), a detail page through its list.
 */
class PortalShellTest extends TestCase
{
    use RefreshDatabase;

    private function workOn(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));
    }

    public function test_a_customers_menu_is_their_own_pages(): void
    {
        $user = User::factory()->create();
        DomainTld::query()->create(['tld' => 'gr', 'grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]); // something to order

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(route('services'), false)->assertSee(route('invoices'), false)
            ->assertSee(__('navigation.new_order'));
    }

    public function test_a_detail_page_goes_back_through_its_list(): void
    {
        $user = User::factory()->create();
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active]);

        $html = $this->workOn($user)->get(route('domains.show', $domain))->assertOk()->getContent();

        $nav = substr($html, (int) strpos($html, 'aria-label="' . __('navigation.breadcrumb') . '"'), 1500);
        $this->assertStringContainsString('href="' . route('dashboard') . '"', $nav, 'from Home');
        $this->assertStringContainsString('href="' . route('domains') . '"', $nav, 'through its list');
        $this->assertStringContainsString('aria-current="page">nixpal.com', $nav);
        $this->assertStringNotContainsString('href="#"', $nav, 'no step goes nowhere');
    }

    public function test_a_list_page_goes_back_to_home(): void
    {
        $user = User::factory()->create();
        $html = $this->workOn($user)->get(route('invoices'))->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, 'aria-label="' . __('navigation.breadcrumb') . '"'), 800);

        $this->assertStringContainsString('href="' . route('dashboard') . '"', $nav);
        $this->assertStringContainsString('aria-current="page">' . __('navigation.invoices'), $nav);
    }

    public function test_a_proforma_and_a_listed_page_keep_their_way_back(): void
    {
        // Code review 1, 2 of #226: a proforma has no number yet; page 2 of a list is still that list.
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.invoice_proforma' => true, 'settings.default_currency' => 'EUR']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'number' => null]);

        $html = $this->workOn($user)->get(route('invoices.show', $invoice))->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, 'aria-label="' . __('navigation.breadcrumb') . '"'), 1500);
        $this->assertStringContainsString('aria-current="page">' . e(__('invoices.proforma_invoice', ['id' => $invoice->id])), $nav);

        $html = $this->workOn($user)->get(route('invoices') . '?page=2')->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, 'aria-label="' . __('navigation.breadcrumb') . '"'), 800);
        $this->assertStringContainsString('aria-current="page">' . __('navigation.invoices'), $nav);
    }
}

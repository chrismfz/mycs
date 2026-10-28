<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial review of the admin's speed (indexes, cached badges, searchable client filters). */
class AdvPerfAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /**
     * ADV-2. The domains' client filter lost its indicator. As a relationship filter Filament labelled the active
     * filter («Client: #5 Name»); with attribute('client_id') and no options its default indicator looks the label
     * up in getOptions(), which is empty, so no indicator is shown: staff see the list narrowed to one account with
     * no chip saying so or removing it. (Tickets kept their own indicateUsing; domains and services did not.)
     */
    public function test_the_domains_client_filter_shows_which_client(): void
    {
        $domain = Domain::factory()->create();
        $client = Client::query()->findOrFail($domain->client_id);

        $page = $this->livewireAs($this->staff(['admin.domains.viewAny']))->test(ListDomains::class)
            ->filterTable('client', $client->id)
            ->assertCanSeeTableRecords([$domain]);

        $this->assertNotEmpty($page->instance()->getTable()->getFilter('client')->getIndicators(), 'an active client filter has an indicator');
    }

    /** ADV-2, the services' list: the same filter, the same missing indicator. */
    public function test_the_services_client_filter_shows_which_client(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);

        $page = $this->livewireAs($this->staff(['admin.services.viewAny']))->test(ListService::class)
            ->filterTable('client', $service->client_id)
            ->assertCanSeeTableRecords([$service]);

        $this->assertNotEmpty($page->instance()->getTable()->getFilter('client')->getIndicators(), 'an active client filter has an indicator');
    }
}

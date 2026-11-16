<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-16: the useful ones of WHMCS's «Advanced» client search, light. */
class ClientListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Client $a;

    private Client $b;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $this->freezeTime();
        $this->a = User::factory()->create()->client();
        $this->b = User::factory()->create()->client();
    }

    private function list(): mixed
    {
        return Livewire::test(ListClients::class);
    }

    public function test_owes_unpaid_overdue_or_nothing(): void
    {
        Invoice::factory()->create(['client_id' => $this->a->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(5)]);
        Invoice::factory()->create(['client_id' => $this->b->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(5)]);

        $this->list()->filterTable('owes', 'overdue')->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
        $this->list()->filterTable('owes', 'unpaid')->assertCanSeeTableRecords([$this->a, $this->b]);

        Invoice::query()->where('client_id', $this->b->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->list()->filterTable('owes', 'none')->assertCanSeeTableRecords([$this->b])->assertCanNotSeeTableRecords([$this->a]);
    }

    public function test_a_saved_payment_method_of_its_own(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $this->a->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->assertNotNull($this->a->fresh()->default_billing_agreement_id, 'precondition');

        $this->list()->filterTable('payment_method', true)->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
        $this->list()->filterTable('payment_method', false)->assertCanSeeTableRecords([$this->b])->assertCanNotSeeTableRecords([$this->a]);
    }

    public function test_the_country_of_its_main_contact_among_those_the_clients_have(): void
    {
        $this->a->primaryContact->update(['country' => 'GR']);
        $this->b->primaryContact->update(['country' => 'DE']);

        $page = $this->list()->filterTable('country', 'GR')->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
        $options = $page->instance()->getTable()->getFilter('country')->getOptions();
        $this->assertSame(['DE', 'GR'], collect($options)->keys()->sort()->values()->all());
    }

    public function test_special_settings_any_of_those_picked(): void
    {
        $this->a->update(['tax_exempt' => true]);
        $this->b->update(['auto_charge' => false]);

        $this->list()->filterTable('settings', ['tax_exempt'])->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
        $this->list()->filterTable('settings', ['tax_exempt', 'no_auto_charge'])->assertCanSeeTableRecords([$this->a, $this->b]);
        $this->list()->filterTable('settings', ['dunning_exempt'])->assertCanNotSeeTableRecords([$this->a, $this->b]);
    }

    public function test_signed_up_between(): void
    {
        $this->a->forceFill(['created_at' => '2026-10-05 10:00:00'])->saveQuietly();
        $this->b->forceFill(['created_at' => '2026-11-10 10:00:00'])->saveQuietly();

        $this->list()->filterTable('created_at', ['created_from' => '2026-10-01', 'created_until' => '2026-10-31'])
            ->assertCanSeeTableRecords([$this->a])->assertCanNotSeeTableRecords([$this->b]);
    }
}

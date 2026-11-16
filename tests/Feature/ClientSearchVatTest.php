<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-16: «καμιά φορά ψάχνουμε με ΑΦΜ». The clients' list and the global search find a client by it. */
class ClientSearchVatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    public function test_the_list_finds_a_client_by_vat_with_or_without_prefix_by_phone_and_by_another_contact(): void
    {
        $acme = User::factory()->create()->client();
        $acme->primaryContact->update(['company_name' => 'Acme', 'tax_id' => '099887766', 'vat_number' => 'EL099887766', 'phone' => '+30 210 5550123']);
        $other = User::factory()->create()->client();
        $other->primaryContact->update(['company_name' => 'Other', 'tax_id' => '011223344']);
        Contact::factory()->create(['client_id' => $other->id, 'email' => 'billing@other-co.test']);

        foreach (['099887766', 'EL099887766', 'el 099 887 766', '5550123', '2105550123', '210-555-0123'] as $search) {
            Livewire::test(ListClients::class)->searchTable($search)
                ->assertCanSeeTableRecords([$acme])->assertCanNotSeeTableRecords([$other]);
        }
        Livewire::test(ListClients::class)->searchTable('billing@other-co')
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$acme]);
    }

    public function test_the_global_search_finds_it_by_vat_and_by_phone(): void
    {
        $acme = User::factory()->create()->client();
        $acme->primaryContact->update(['company_name' => 'Acme', 'tax_id' => '099887766', 'phone' => '+30 210 5550123']);

        foreach (['099887766', '5550123'] as $search) {
            $results = ClientResource::getGlobalSearchResults($search);

            $this->assertCount(1, $results, $search);
            $this->assertStringStartsWith('#' . $acme->id . ' ', (string) $results->first()->title);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainTldResource\Pages\EditDomainTld;
use App\Admin\Resources\DomainTldResource\RelationManagers\PricesRelationManager;
use App\Models\Currency;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Independent review of fix/adv-area-5: the admin price form's per-operation minimum. */
class Review5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
    }

    private function page(DomainTld $tld)
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);

        return $this->livewireAs($staff)->test(PricesRelationManager::class, ['ownerRecord' => $tld, 'pageClass' => EditDomainTld::class]);
    }

    public function test_the_form_refuses_a_free_registration_or_renewal_and_keeps_a_free_transfer_or_fee(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com']);
        foreach (['register', 'renew'] as $operation) {
            $this->page($tld)->callTableAction('create', data: ['operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => '0'])
                ->assertHasTableActionErrors(['price']);
        }
        $this->page($tld)->callTableAction('create', data: ['operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.01'])
            ->assertHasNoTableActionErrors();
        foreach (['transfer', 'grace', 'redemption'] as $operation) {
            $this->page($tld)->callTableAction('create', data: ['operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => '0'])
                ->assertHasNoTableActionErrors();
        }
        $tld->load('prices');
        $this->assertSame(['0.01', '0.00', '0.00', '0.00'], [$tld->priceFor('register', 1, 'EUR'), $tld->priceFor('transfer', 1, 'EUR'),
            $tld->priceFor('grace', 1, 'EUR'), $tld->priceFor('redemption', 1, 'EUR')]);
    }
}

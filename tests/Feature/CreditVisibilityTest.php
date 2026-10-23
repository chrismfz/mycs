<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Widgets\Overview;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Unused account credit on the dashboard (owner 2026-10-23: a transfer recorded twice becomes credit, and nobody saw
 * it): how many accounts hold credit and how much, opening the client list on them.
 */
class CreditVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.credits_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    /** @return array<string, Stat> */
    private function stats(): array
    {
        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());

        return collect($stats)->keyBy(fn (Stat $stat) => (string) $stat->getLabel())->all();
    }

    public function test_the_dashboard_counts_the_accounts_with_credit_and_how_much(): void
    {
        $this->staff(['*']);
        $maria = User::factory()->create()->client();
        $nikos = User::factory()->create()->client();
        $none = User::factory()->create()->client();
        (new CreditLedger)->add($maria->id, 'EUR', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Paid twice');
        (new CreditLedger)->add($nikos->id, 'EUR', 250, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Overpaid');

        $stat = $this->stats()[__('Clients with credit')];

        $this->assertSame('2', (string) $stat->getValue());
        $this->assertStringContainsString('42,50', (string) $stat->getDescription());
        $this->assertStringContainsString('filters', (string) $stat->getUrl());

        Livewire::test(ListClients::class)->filterTable('with_credit', true)
            ->assertCanSeeTableRecords([$maria, $nikos])->assertCanNotSeeTableRecords([$none]);
    }

    public function test_gray_at_zero(): void
    {
        $this->staff(['*']);

        $stat = $this->stats()[__('Clients with credit')];

        $this->assertSame(['0', 'gray'], [(string) $stat->getValue(), $stat->getColor()]);
    }

    public function test_only_to_staff_who_may_see_credits(): void
    {
        $this->staff(['admin.widgets.overview', 'admin.clients.viewAny', 'admin.orders.viewAny']);

        $this->assertArrayNotHasKey(__('Clients with credit'), $this->stats());
    }
}

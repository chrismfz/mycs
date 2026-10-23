<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Widgets\Overview;
use App\Models\Credit;
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

class CreditVisibilityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.credits_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function stat(): Stat
    {
        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());

        return collect($stats)->keyBy(fn (Stat $s) => (string) $s->getLabel())->all()[__('Clients with credit')];
    }

    public function test_the_card_url_opens_the_list_filtered(): void
    {
        $this->staff(['*']);
        $with = User::factory()->create(['first_name' => 'Withcredit', 'last_name' => 'Zzq'])->client();
        $without = User::factory()->create(['first_name' => 'Nocredit', 'last_name' => 'Yyq'])->client();
        (new CreditLedger)->add($with->id, 'EUR', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');

        $this->get($this->stat()->getUrl())->assertOk()->assertSee('Withcredit')->assertDontSee('Nocredit');
    }

    public function test_a_crafted_url_without_the_permission_does_not_filter(): void
    {
        $this->staff(['admin.clients.viewAny', 'admin.clients.view']);
        $with = User::factory()->create(['first_name' => 'Withcredit', 'last_name' => 'Zzq'])->client();
        User::factory()->create(['first_name' => 'Nocredit', 'last_name' => 'Yyq'])->client();
        (new CreditLedger)->add($with->id, 'EUR', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');

        $this->get(ClientResource::getUrl('index', ['filters' => ['with_credit' => ['value' => '1']]]))->assertOk()->assertSee('Withcredit')->assertSee('Nocredit');
    }

    public function test_negative_balances_and_several_currencies(): void
    {
        $this->staff(['*']);
        $a = User::factory()->create()->client();
        $b = User::factory()->create()->client();
        $c = User::factory()->create()->client();
        (new CreditLedger)->add($a->id, 'EUR', 10, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');
        (new CreditLedger)->add($a->id, 'XYZ', 12345, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');
        (new CreditLedger)->add($b->id, 'EUR', 20, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');
        Credit::query()->create(['client_id' => $c->id, 'currency_code' => 'EUR', 'amount' => '-5.00']);

        $stat = $this->stat();
        $this->assertSame('2', (string) $stat->getValue());
        $this->assertStringContainsString('0,30', (string) $stat->getDescription());
        $this->assertStringContainsString('123.45 XYZ', (string) $stat->getDescription());
    }
}

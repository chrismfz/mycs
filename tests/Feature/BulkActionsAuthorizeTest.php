<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/** A custom bulk action asks the policy itself: Filament authorizes only its own DeleteBulkAction. */
class BulkActionsAuthorizeTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10]);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            // refused by throwing is fine too
        }
    }

    public function test_seeing_services_is_not_deleting_them(): void
    {
        $this->staff(['admin.services.viewAny']);

        $this->attempt(fn () => Livewire::test(ListService::class)->callTableBulkAction('delete', [$this->service], ['deleteExtensionServer' => false]));

        $this->assertModelExists($this->service);
    }

    public function test_staff_who_may_delete_services_still_do(): void
    {
        $this->staff(['admin.services.viewAny', 'admin.services.deleteAny', 'admin.services.delete']);

        Livewire::test(ListService::class)->callTableBulkAction('delete', [$this->service], ['deleteExtensionServer' => false]);

        $this->assertModelMissing($this->service);
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Logs;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\Audits\Pages\ListAudits;
use App\Models\Audit;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** Review of #402. */
class ActivityLogReviewTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['first_name' => 'Maria', 'last_name' => 'Admin',
            'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    /** An audit of a model whose class is gone (an extension removed, a class renamed). */
    private function orphanAudit(): Audit
    {
        return Audit::query()->forceCreate([
            'user_type' => null, 'user_id' => null, 'event' => 'updated',
            'auditable_type' => 'Paymenter\\Extensions\\Gone\\Models\\Thing', 'auditable_id' => 1,
            'old_values' => ['name' => 'a'], 'new_values' => ['name' => 'b'],
            'url' => 'console', 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
        ]);
    }

    public function test_the_list_survives_an_audit_of_a_class_that_no_longer_exists(): void
    {
        $audit = $this->orphanAudit();
        $this->actingAs($this->staff, 'staff');

        $this->get(AuditResource::getUrl('index'))->assertOk();
        Livewire::test(ListAudits::class)->assertCanSeeTableRecords([$audit]);
    }

    public function test_the_view_page_survives_an_audit_of_a_class_that_no_longer_exists(): void
    {
        $audit = $this->orphanAudit();
        $this->actingAs($this->staff, 'staff');

        $this->get(AuditResource::getUrl('view', ['record' => $audit]))->assertOk();
    }

    /** On a Livewire update (a filter, a page of the table) the route is livewire.update: the item must stay active. */
    public function test_the_current_log_stays_active_on_a_livewire_update(): void
    {
        $this->actingAs($this->staff, 'staff');

        $original = Request::create(AuditResource::getUrl('index'));
        $original->setRouteResolver(fn () => (new Route('GET', 'admin/audits', []))->name(AuditResource::getRouteBaseName() . '.index'));
        $this->app->instance('originalRequest', $original);

        $update = Request::create('/livewire/update', 'POST');
        $update->setRouteResolver(fn () => (new Route('POST', 'livewire/update', []))->name('livewire.update'));
        $this->app->instance('request', $update);

        $active = array_values(array_filter(Logs::navigation(), fn ($item) => $item->isActive()));
        $this->assertCount(1, $active);
        $this->assertSame(__('Activity Log'), $active[0]->getLabel());
    }
}

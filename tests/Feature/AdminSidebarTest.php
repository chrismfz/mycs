<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\ClientResource;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin's sidebar is for the day's work (owner 2026-10-21); the full menu is the top menu (TopMenu). */
class AdminSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_what_waits_on_staff_has_its_count_and_what_does_not_is_there_dimmed(): void
    {
        $this->staff();
        Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'guest_name' => 'M', 'guest_email' => 'm@example.com']);

        $queues = collect(AdminSidebar::queues())->keyBy('label');

        $this->assertSame('1', $queues['Tickets awaiting reply']['badge']);
        $this->assertStringContainsString('tab=open', $queues['Tickets awaiting reply']['url']);
        $this->assertNull($queues['Pending orders']['badge'], 'nothing pending: shown, without a count');
        $this->assertSame(['New client', 'New order', 'New invoice', 'New ticket'], array_column(AdminSidebar::creates(), 'label'));
    }

    public function test_staff_see_only_the_queues_and_creates_they_may_open(): void
    {
        $this->staff(['admin.tickets.viewAny', 'admin.tickets.create']);

        $this->assertSame(['Tickets awaiting reply'], array_column(AdminSidebar::queues(), 'label'));
        $this->assertSame(['New ticket'], array_column(AdminSidebar::creates(), 'label'));
    }

    public function test_a_client_opened_is_in_recently_viewed_newest_first_once(): void
    {
        $staff = $this->staff();
        $one = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Pappa'])->client();
        $two = User::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Papas'])->client();
        $session = $this->withSession($this->loginUser($staff));

        $session->get(ClientResource::getUrl('view', ['record' => $one]))->assertOk();
        $session->get(ClientResource::getUrl('view', ['record' => $two]))->assertOk();
        $page = $session->get(ClientResource::getUrl('edit', ['record' => $one]))->assertOk();

        $this->assertSame([$one->name, $two->name], array_column(AdminSidebar::recent(), 'label'));
        $page->assertSee(__('Recently viewed'));
    }

    public function test_the_sidebar_renders_the_work_list_and_keeps_the_full_menu_for_a_phone(): void
    {
        $staff = $this->staff();

        $this->withSession($this->loginUser($staff))->get('/sysadmin')->assertOk()
            ->assertSee('mycs-work-sidebar', false)->assertSee(__('To handle'))
            ->assertSee('fi-sidebar-nav-groups', false); // hidden by CSS from lg up, where the top menu is
    }

    /** A resource in a cluster with no badge of its own (services) still has its count (review of #277's fix). */
    public function test_pending_services_have_their_count(): void
    {
        $this->staff();
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1]);

        $this->assertSame('1', collect(AdminSidebar::queues())->firstWhere('label', 'Pending services')['badge']);
    }
}

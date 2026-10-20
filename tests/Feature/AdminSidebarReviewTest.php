<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of #277 (the admin's work sidebar): failing tests for what was found. */
class AdminSidebarReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    /** @return array<string, mixed> the query string of a sidebar queue's link */
    private function queueQuery(string $label): array
    {
        $queue = collect(AdminSidebar::queues())->firstWhere('label', $label);
        $this->assertNotNull($queue, $label);
        parse_str((string) parse_url($queue['url'], PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * R1: the sidebar asks each queue's getNavigationBadge() again, though Filament's own navigation (built in the same
     * request for the same sidebar) already counted it: ~9 duplicate COUNT(*) on every admin page.
     */
    public function test_each_queue_is_counted_once_per_page(): void
    {
        $staff = $this->staff();
        $session = $this->withSession($this->loginUser($staff));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $session->get('/admin')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        foreach (['"tickets" where "status" in', '"invoices" where "status" =', '"services" where "status" =', '"ticket_quarantine"', '"mcp_proposals"'] as $needle) {
            $count = $queries->filter(fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, $needle))->count();
            $this->assertLessThanOrEqual(1, $count, "count of {$needle} ran {$count} times in one page");
        }
    }

    /** R2: «Unpaid invoices (n)» opens the whole invoice list, not the n it counted. */
    public function test_the_unpaid_invoices_link_lists_the_unpaid_ones(): void
    {
        $this->staff();
        $client = User::factory()->create()->client();
        $unpaid = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()->addWeek()]);
        $paid = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()->addWeek()]);
        $paid->forceFill(['status' => Invoice::STATUS_PAID])->saveQuietly();

        Livewire::withQueryParams($this->queueQuery('Unpaid invoices'))->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$unpaid])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    /** R2: «Pending services (n)» opens every service, not the n pending ones. */
    public function test_the_pending_services_link_lists_the_pending_ones(): void
    {
        $this->staff();
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $make = fn (string $status) => Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        $pending = $make(Service::STATUS_PENDING);
        $active = $make(Service::STATUS_ACTIVE);

        Livewire::withQueryParams($this->queueQuery('Pending services'))->test(ListService::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active]);
    }

    /** R3: «Recently viewed» keeps showing a client's name after staff lost the right to see clients. */
    public function test_recently_viewed_drops_what_staff_may_no_longer_open(): void
    {
        $staff = $this->staff();
        $client = User::factory()->create(['first_name' => 'Secret', 'last_name' => 'Customer'])->client();
        $session = $this->withSession($this->loginUser($staff));
        $session->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk();
        $this->assertNotEmpty(AdminSidebar::recent());

        $staff->role->update(['permissions' => ['admin.tickets.viewAny']]);
        $staff->refresh();
        $this->actingAs($staff, 'staff');

        $session->get('/admin')->assertOk()->assertDontSee('Secret Customer');
    }
}

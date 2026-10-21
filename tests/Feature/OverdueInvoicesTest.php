<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Unpaid ones in time and late ones apart (owner 2026-10-21): a due date column, an «Overdue» filter, a sidebar queue. */
class OverdueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $late;

    private Invoice $inTime;

    private Invoice $paidLate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->travelTo('2026-10-21 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot(); // its bootUsing sets the admin's date format, as a request does
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $client = User::factory()->create()->client();
        $make = fn (string $due) => Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $due]);
        $this->late = $make('2026-10-11');
        $this->inTime = $make('2026-10-21'); // due today: not late yet
        $this->paidLate = $make('2026-10-01');
        $this->paidLate->forceFill(['status' => Invoice::STATUS_PAID])->saveQuietly();
    }

    public function test_overdue_is_unpaid_past_its_due_date(): void
    {
        $this->assertSame([$this->late->id], Invoice::query()->overdue()->pluck('id')->all());
        $this->assertSame([10, 0, 0], [$this->late->daysOverdue(), $this->inTime->daysOverdue(), $this->paidLate->fresh()->daysOverdue()]);
    }

    public function test_the_list_shows_the_due_date_and_how_late_and_filters_the_late_ones(): void
    {
        Livewire::test(ListInvoices::class)->assertSee('11/10/2026')->assertSee('10 days late')
            ->filterTable('overdue')->assertCanSeeTableRecords([$this->late])->assertCanNotSeeTableRecords([$this->inTime, $this->paidLate]);
    }

    public function test_the_sidebar_counts_the_late_ones_and_opens_them(): void
    {
        $queue = collect(AdminSidebar::queues())->firstWhere('label', 'Overdue invoices');

        $this->assertSame(['1', 'danger'], [$queue['badge'], $queue['color']]);
        $this->assertStringContainsString('overdue', urldecode($queue['url']));
        $this->assertSame('2', collect(AdminSidebar::queues())->firstWhere('label', 'Unpaid invoices')['badge'], 'unpaid: in time and late');
    }
}

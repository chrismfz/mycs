<?php

namespace Tests\Feature;

use App\Admin\Widgets\PaymentsTracking;
use App\Models\BankMovement;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** B2's Payments tracking (owner 2026-11-04): one line a movement, the yellow said under the heading. */
class DashboardIncomePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R', 'permissions' => ['admin.widgets.payments_tracking']])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function movement(string $reason): BankMovement
    {
        return BankMovement::query()->create(['bank' => 'piraeus', 'direction' => 'credit', 'amount' => '150.00', 'balance' => '2903.01',
            'reason' => $reason, 'executed_at' => now(), 'message_key' => uniqid(), 'authentic' => true, 'status' => 'pending']);
    }

    public function test_a_long_reference_is_one_line_with_the_whole_of_it_in_its_tooltip(): void
    {
        $long = str_repeat('ΜΕΤΑΦΟΡΑ ΜΕΣΩ E-BANKING ', 4) . '32637';
        $this->movement($long);
        $this->movement('SHORT 32459');

        $html = $this->livewireAs($this->staff())->test(PaymentsTracking::class)
            ->assertSee('SHORT 32459')
            ->assertSee(__('Yellow: not seen to yet'))->html();

        // Cut by the cell's CSS to the room the card has, and the whole of it in a tooltip for the long one only.
        $this->assertSame(2, substr_count($html, 'mycs-cut-cell'));
        $this->assertSame(1, substr_count($html, 'SHORT 32459'), 'a short reference has no tooltip');
        $this->assertGreaterThanOrEqual(2, substr_count($html, '32637'), 'the long one is in its cell and its tooltip');
    }

    public function test_the_search_still_finds_a_reference_by_its_end(): void
    {
        $long = str_repeat('ΜΕΤΑΦΟΡΑ ΜΕΣΩ E-BANKING ', 4) . 'INV12345';
        $this->movement($long);
        $this->movement('OTHER 1');

        $this->livewireAs($this->staff())->test(PaymentsTracking::class)
            ->searchTable('INV12345')->assertCanSeeTableRecords(BankMovement::query()->where('reason', $long)->get())
            ->assertCanNotSeeTableRecords(BankMovement::query()->where('reason', 'OTHER 1')->get());
    }
}

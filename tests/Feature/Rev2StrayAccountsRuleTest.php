<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Second review of «check this tasks are not failures» (owner 2026-11-08). The list's own status column still calls a
 * check «needs staff» in red (danger), while its «Needs staff» filter (scopeNeedsStaff) no longer lists it: the same
 * page says two things about the same row.
 */
class Rev2StrayAccountsRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rev2_1_a_check_is_not_shown_as_needs_staff_in_the_list(): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $check = ModuleAction::query()->create(['action' => ModuleAction::STRAY_UNMAPPED, 'subject' => "{$server->id}:stray", 'module' => 'CPanel',
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 0, 'last_attempt_at' => now(), 'given_up_at' => now(), 'last_error' => 'check it']);

        $list = Livewire::test(ListModuleActions::class);
        // The «Needs staff» filter leaves it out (not a failure) ...
        $list->filterTable('needs_staff')->assertCanNotSeeTableRecords([$check]);
        // ... but the row's status badge still says «needs staff» (red).
        Livewire::test(ListModuleActions::class)->assertCanSeeTableRecords([$check])
            ->assertTableColumnStateNotSet('status', 'needs staff', $check);
    }
}

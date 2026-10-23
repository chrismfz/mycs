<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Support\StaffPresence;
use Illuminate\View\View;
use Livewire\Component;

/**
 * On every page of the admin (docs/livechat.md §7α): a heartbeat every 30 seconds, also from a tab in the background,
 * since a forgotten tab still counts as online (owner 2026-10-23). Whether the member may see the list or not.
 */
class StaffHeartbeat extends Component
{
    public function mount(): void
    {
        $this->beat();
    }

    public function beat(): void
    {
        $staff = auth('staff')->user();
        if ($staff instanceof Staff) {
            StaffPresence::heartbeat($staff);
        }
    }

    public function render(): View
    {
        return view('livewire.admin.staff-heartbeat');
    }
}

<?php

namespace App\Livewire\Admin;

use App\Enums\StaffPresenceStatus;
use App\Models\Staff;
use App\Support\StaffPresence;
use Illuminate\View\View;
use Livewire\Component;

/**
 * «Staff online» in the admin's sidebar (docs/livechat.md §7β): who is in, with the dot of their status, and the
 * member's own status, changed by hand only. Names and statuses, never an address or a device.
 */
class StaffOnline extends Component
{
    public function setStatus(string $status): void
    {
        $staff = auth('staff')->user();
        $status = StaffPresenceStatus::tryFrom($status);
        if (!$staff instanceof Staff || $status === null) {
            return;
        }
        StaffPresence::setStatus($staff, $status);
    }

    public function render(): View
    {
        $staff = auth('staff')->user();
        $staff = $staff instanceof Staff ? $staff->fresh() : null;

        return view('livewire.admin.staff-online', [
            'canView' => StaffPresence::canView($staff),
            'me' => $staff,
            'myStatus' => $staff ? StaffPresence::status($staff) : null,
            'online' => StaffPresence::canView($staff) ? StaffPresence::online() : [],
            'statuses' => StaffPresenceStatus::cases(),
        ]);
    }
}

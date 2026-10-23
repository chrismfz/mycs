<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Support\StaffPresence;
use Illuminate\View\View;
use Livewire\Component;

/**
 * «Team» on the Live chat page (docs/livechat.md §7α): every member with their status and «connected now» or when last
 * seen. Names and statuses only, as «Staff online» (§7β); the chats each one has come with the customers' chat.
 */
class StaffTeam extends Component
{
    public function render(): View
    {
        $staff = auth('staff')->user();

        return view('livewire.admin.staff-team', [
            'roster' => StaffPresence::canView($staff instanceof Staff ? $staff : null) ? StaffPresence::roster() : [],
        ]);
    }
}

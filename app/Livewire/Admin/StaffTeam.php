<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Support\StaffChat;
use App\Support\StaffPresence;
use Illuminate\View\View;
use Livewire\Component;

/**
 * «Team» on the Live chat page (docs/livechat.md §7α, §7γ): the room and every member, with their status and «connected
 * now» or when last seen, and how many of their messages wait. A click opens the room or the private thread with that
 * member. Names and statuses only, as «Staff online» (§7β); the chats each one has come with the customers' chat.
 */
class StaffTeam extends Component
{
    public function render(): View
    {
        $staff = auth('staff')->user();
        $staff = $staff instanceof Staff ? $staff : null;

        return view('livewire.admin.staff-team', [
            'me' => $staff,
            'roster' => StaffPresence::canView($staff) ? StaffPresence::roster() : [],
            'canChat' => StaffChat::canView($staff),
            'unread' => $staff ? StaffChat::unreadByThread($staff) : [],
        ]);
    }
}

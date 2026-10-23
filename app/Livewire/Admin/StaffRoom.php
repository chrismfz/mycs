<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Support\StaffChat;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The staff's shared room on the Live chat page (docs/livechat.md §7γ). The only state the browser holds is the draft;
 * the thread, the author and the right to read or write come from the server on every call (F7).
 */
class StaffRoom extends Component
{
    public string $body = '';

    public function send(): void
    {
        $staff = auth('staff')->user();
        if (!$staff instanceof Staff) {
            return;
        }
        $refusal = StaffChat::post($staff, StaffChat::room(), $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    public function render(): View
    {
        $staff = auth('staff')->user();
        $staff = $staff instanceof Staff ? $staff : null;
        $canView = StaffChat::canView($staff);
        $messages = collect();
        if ($canView && $staff !== null) {
            $room = StaffChat::room();
            $messages = StaffChat::messages($room);
            StaffChat::markRead($staff, $room, $messages->last()?->id);
        }

        return view('livewire.admin.staff-room', [
            'canView' => $canView,
            'canPost' => StaffChat::canPost($staff),
            'me' => $staff,
            'messages' => $messages,
        ]);
    }
}

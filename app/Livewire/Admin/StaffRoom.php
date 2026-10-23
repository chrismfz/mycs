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

    /**
     * The browser calls this only while the tab is in front (review of #373): a poll from a tab in the background must
     * not mark the room read. It moves the member's own mark only, so the browser saying so is harmless.
     */
    public function markSeen(int $upTo): void
    {
        $staff = auth('staff')->user();
        if ($staff instanceof Staff && StaffChat::canView($staff)) {
            $room = StaffChat::room();
            StaffChat::markRead($staff, $room, min($upTo, (int) $room->messages()->max('id')));
        }
    }

    public function render(): View
    {
        $staff = auth('staff')->user();
        $staff = $staff instanceof Staff ? $staff : null;
        $canView = StaffChat::canView($staff);
        $messages = collect();
        if ($canView && $staff !== null) {
            $messages = StaffChat::messages(StaffChat::room());
        }

        return view('livewire.admin.staff-room', [
            'canView' => $canView,
            'canPost' => StaffChat::canPost($staff),
            'me' => $staff,
            'messages' => $messages,
        ]);
    }
}

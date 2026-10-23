<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Models\StaffSession;
use App\Support\StaffSessions;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A member's open sessions, to end one or all (StaffSessions): the member's own in their profile, another member's on
 * their staff page for whoever has admin.staff.sessions. Addresses are shown here only, to the member and to them.
 */
class StaffSessionList extends Component
{
    #[Locked]
    public int $staffId;

    public function mount(int $staffId): void
    {
        $this->staffId = $staffId;
        $this->member();
    }

    /** The member whose sessions these are, if the viewer may see them (checked again on every action). */
    private function member(): Staff
    {
        $viewer = auth('staff')->user();
        $member = Staff::query()->find($this->staffId);
        abort_unless($viewer instanceof Staff && $member !== null && ($viewer->is($member) || $viewer->hasPermission('admin.staff.sessions')), 403);

        return $member;
    }

    public function end(string $ulid): void
    {
        $member = $this->member();
        $row = StaffSessions::open($member)->where('ulid', $ulid)->first();
        if ($row === null || $this->isCurrent($row)) {
            return; // this browser logs out with «Sign out»
        }
        StaffSessions::end($member, [$row], auth('staff')->user(), request());
        Notification::make()->success()->title(__('The session was ended.'))->send();
    }

    /** Every session but this browser's (all of them, on another member's page). */
    public function endOthers(): void
    {
        $member = $this->member();
        $rows = StaffSessions::open($member)->get()->reject(fn (StaffSession $row) => $this->isCurrent($row));
        $count = StaffSessions::end($member, $rows, auth('staff')->user(), request());
        Notification::make()->success()->title(trans_choice('{0} No other session was open.|{1} One session was ended.|[2,*] :count sessions were ended.', $count))->send();
    }

    private function isCurrent(StaffSession $row): bool
    {
        return $row->ulid === StaffSessions::store(request())->get(StaffSessions::SESSION_KEY);
    }

    public function render(): View
    {
        $member = $this->member();
        $sessions = StaffSessions::open($member)->orderByDesc('last_seen_at')->get();

        return view('livewire.admin.staff-session-list', [
            'sessions' => $sessions,
            'current' => StaffSessions::store(request())->get(StaffSessions::SESSION_KEY),
            'own' => $member->is(auth('staff')->user()),
        ]);
    }
}

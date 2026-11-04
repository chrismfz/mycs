<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\UserResource;
use App\Models\Staff;
use App\Models\UserSession;
use App\Support\StaffPresence;
use Filament\Widgets\Widget;
use Illuminate\View\View;

/**
 * «Staff and sessions» (B2, owner 2026-11-04): «Staff online» and «Active Users» in one card, each part with its own
 * permission. The staff part keeps docs/livechat.md §7β: who is in, never from where (no address, IP or device). The
 * customers' part is the sessions of the last hour, as Active Users showed them, with their IP.
 */
class StaffAndSessions extends Widget
{
    /** A customer's session counts as open for this long after its last request. */
    public const SESSION_MINUTES = 60;

    /** The customers' sessions listed; the heading counts all of them. */
    public const SESSIONS_SHOWN = 5;

    protected string $view = 'admin.widgets.staff-and-sessions';

    // Near the end, the width of the page (below the two columns).
    protected static ?int $sort = 29;

    protected int|string|array $columnSpan = 'full';

    public function render(): View
    {
        $staff = self::seesStaff() ? StaffPresence::roster() : null;
        $sessions = null;
        $sessionCount = 0;
        if (self::seesSessions()) {
            $query = UserSession::query()
                ->where('last_activity', '>=', now()->subMinutes(self::SESSION_MINUTES))
                ->whereNotNull('user_id')
                ->whereHas('user');
            $sessionCount = (clone $query)->count();
            $sessions = $query->orderByDesc('last_activity')->with('user')->limit(self::SESSIONS_SHOWN)->get()
                ->map(fn (UserSession $session) => [
                    'name' => $session->user->name,
                    'email' => $session->user->email,
                    'url' => UserResource::getUrl('edit', ['record' => $session->user]),
                    'last_activity' => $session->last_activity,
                    'ip' => (string) $session->ip_address,
                ])->all();
        }

        return view($this->view, [
            'staff' => $staff,
            'online' => $staff === null ? 0 : count(array_filter($staff, fn (array $member) => $member['online'])),
            'sessions' => $sessions,
            'sessionCount' => $sessionCount,
        ]);
    }

    private static function seesStaff(): bool
    {
        $staff = auth('staff')->user();

        return StaffPresence::canView($staff instanceof Staff ? $staff : null);
    }

    private static function seesSessions(): bool
    {
        return (bool) auth('staff')->user()?->hasPermission('admin.widgets.active_users');
    }

    public static function canView(): bool
    {
        return self::seesStaff() || self::seesSessions();
    }
}

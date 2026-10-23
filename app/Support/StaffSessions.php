<?php

namespace App\Support;

use App\Models\McpOauthGrant;
use App\Models\Staff;
use App\Models\StaffSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OwenIt\Auditing\Events\AuditCustom;
use Throwable;

/**
 * A member of staff's logins, one per browser session, so that one on a lost or shared laptop can be ended (owner
 * 2026-10-23): by the member in their profile, or by an admin with admin.staff.sessions on the member's page.
 *
 * A login writes its row and keeps its id in the session; every admin request checks it (TrackStaffSession) and shows
 * when it was last seen. Ending one destroys its session in the store, rotates the member's «remember me» token (every
 * remembered browser of theirs logs in again) and is audited on the member; the ended row refuses its session on its
 * next request even if the store kept it.
 */
final class StaffSessions
{
    /** The session's key holding its row's ulid. */
    public const SESSION_KEY = 'staff_session';

    /** `last_seen_at` is written at most this often. */
    public const TOUCH_EVERY = 60;

    /** Ended rows are kept this many days, as the record of who ended what (the audit keeps it too). */
    public const KEEP_ENDED_DAYS = 30;

    /** A login of this request's session: its row, kept in the session (a login on a session that had one replaces it). */
    public static function started(Staff $staff, bool $remembered, Request $request): StaffSession
    {
        $session = self::store($request);
        $current = self::current($staff, $request);
        if ($current !== null) {
            // The same browser logging in again (or «remember me» re-issued): its row stays.
            $current->forceFill(['remembered' => $current->remembered || $remembered])->save();

            return $current;
        }
        self::prune($staff);
        $row = StaffSession::query()->create([
            'staff_id' => $staff->id,
            'session_id' => $session->getId(),
            'ip_address' => CallerIp::of($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'remembered' => $remembered,
            'created_at' => now(),
            'last_seen_at' => now(),
        ]);
        $session->put(self::SESSION_KEY, $row->ulid);

        return $row;
    }

    /** This request's row, if it has one that is the member's and not ended. */
    public static function current(Staff $staff, Request $request): ?StaffSession
    {
        $ulid = self::store($request)->get(self::SESSION_KEY);

        return is_string($ulid)
            ? StaffSession::query()->where('ulid', $ulid)->where('staff_id', $staff->id)->whereNull('ended_at')->first()
            : null;
    }

    /**
     * Every admin request of a logged-in member: false when its session was ended (the caller logs it out). A session
     * from before the rows (or whose row was pruned) gets one.
     */
    public static function check(Staff $staff, Request $request): bool
    {
        $ulid = self::store($request)->get(self::SESSION_KEY);
        $row = is_string($ulid) ? StaffSession::query()->where('ulid', $ulid)->first() : null;
        if ($row !== null && $row->staff_id === $staff->id && $row->ended_at !== null) {
            return false;
        }
        if ($row === null || $row->staff_id !== $staff->id) {
            // Another member's row in this session (a login the listener did not see): this member's own, afresh.
            self::started($staff, Auth::guard('staff')->viaRemember(), $request);

            return true;
        }

        return true;
    }

    /** Whether this session's row of $staff was ended (the staff guard asks, on every request that uses it). */
    public static function endedIn(Staff $staff, Session $session): bool
    {
        $ulid = $session->get(self::SESSION_KEY);

        return is_string($ulid) && StaffSession::query()->where('ulid', $ulid)->where('staff_id', $staff->id)->whereNotNull('ended_at')->exists();
    }

    /**
     * At the end of every request of a session with a row (the admin's, the customer area's, the export, the MCP
     * consent): the row keeps the session's current id (a login regenerates it after its row was written) and when it
     * was last seen. One conditional update, not through the model.
     */
    public static function touch(Session $session, Request $request): void
    {
        $ulid = $session->get(self::SESSION_KEY);
        if (!is_string($ulid)) {
            return;
        }
        $id = $session->getId();
        StaffSession::query()->toBase()->where('ulid', $ulid)->whereNull('ended_at')
            ->where(fn ($query) => $query->whereNull('session_id')->orWhere('session_id', '!=', $id)
                ->orWhereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(self::TOUCH_EVERY)))
            ->update(['session_id' => $id, 'last_seen_at' => now(), 'ip_address' => CallerIp::of($request)]);
    }

    /**
     * The member's sessions still open: not ended, and either remembered or seen within the session's lifetime.
     *
     * @return Builder<StaffSession>
     */
    public static function open(Staff $staff): Builder
    {
        return StaffSession::query()->where('staff_id', $staff->id)->whereNull('ended_at')
            ->where(fn (Builder $query) => $query->where('remembered', true)
                ->orWhere('last_seen_at', '>', now()->subMinutes((int) config('session.lifetime', 120))));
    }

    /** A logout of this browser: its row ends (by its own member). */
    public static function loggedOut(Staff $staff, Request $request): void
    {
        $row = self::current($staff, $request);
        $row?->forceFill(['ended_at' => now(), 'ended_by_staff_id' => $staff->id])->save();
    }

    /**
     * Ends these sessions of one member, by $by. The «remember me» token is rotated, so no remembered browser of theirs
     * comes back by itself; the caller's own browser, when it is the member's and was remembered, is remembered again.
     *
     * @param  iterable<StaffSession>  $sessions
     */
    public static function end(Staff $staff, iterable $sessions, Staff $by, Request $request): int
    {
        $ended = 0;
        $since = null;
        $ulids = [];
        $currentId = self::store($request)->getId();
        foreach ($sessions as $row) {
            if ($row->staff_id !== $staff->id || $row->ended_at !== null) {
                continue;
            }
            $row->forceFill(['ended_at' => now(), 'ended_by_staff_id' => $by->id])->save();
            $ulids[] = $row->ulid;
            $since = $since === null || ($row->created_at !== null && $row->created_at->lt($since)) ? $row->created_at : $since;
            if (is_string($row->session_id) && $row->session_id !== '' && $row->session_id !== $currentId) {
                try {
                    self::store($request)->getHandler()->destroy($row->session_id);
                } catch (Throwable $e) {
                    // The ended row refuses it anyway (TrackStaffSession).
                    report($e);
                }
            }
            $ended++;
        }
        if ($ended === 0) {
            return 0;
        }

        // The MCP connectors those sessions approved (a lost laptop's): by the session they were approved in. One
        // approved before grants named their session is matched by time, since the earliest ended session started.
        McpOauthGrant::query()->where('staff_id', $staff->id)->where('approved_by', 'session')->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereIn('staff_session', $ulids)
                ->orWhere(fn ($old) => $old->whereNull('staff_session')->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))))
            ->get()->each(fn (McpOauthGrant $grant) => $grant->revoke('Session ended'));

        $staff->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        $mine = $by->is($staff) ? self::current($staff, $request) : null;
        if ($mine !== null && $mine->remembered && Auth::guard('staff')->check()) {
            // This browser stays remembered, with the new token (the old cookie is now worthless).
            Auth::guard('staff')->login($staff, true);
        }

        $staff->auditEvent = 'sessions_ended';
        $staff->isCustomEvent = true;
        $staff->auditCustomOld = [];
        $staff->auditCustomNew = ['sessions' => $ended, 'by' => "#{$by->id} " . trim($by->first_name . ' ' . $by->last_name)];
        event(new AuditCustom($staff));
        $staff->isCustomEvent = false;
        $staff->auditCustomNew = [];
        Log::info('Staff sessions ended', ['staff_id' => $staff->id, 'by' => $by->id, 'sessions' => $ended]);

        return $ended;
    }

    /** The request's session (the app's own store where a request has none attached, as in a Livewire test). */
    public static function store(Request $request): Session
    {
        return $request->hasSession() ? $request->session() : app('session.store');
    }

    /** Rows ended long ago, or not seen for longer than any «remember me» lasts. */
    private static function prune(Staff $staff): void
    {
        StaffSession::query()->where('staff_id', $staff->id)
            ->where(fn (Builder $query) => $query->where('ended_at', '<', now()->subDays(self::KEEP_ENDED_DAYS))
                ->orWhere('last_seen_at', '<', now()->subDays(400)))
            ->delete();
    }
}

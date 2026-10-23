<?php

namespace App\Support;

use App\Enums\StaffPresenceStatus;
use App\Models\Staff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Who of the staff is in the admin (docs/livechat.md §5, §7β). An open admin page sends a heartbeat every 30 seconds;
 * «online» is a heartbeat in the last 90 seconds. A heartbeat says a browser is open, not that someone sits at it: the
 * list says «connected», never «active».
 */
class StaffPresence
{
    /** Seconds a heartbeat keeps a member online: three missed beats of 30 seconds. */
    public const TTL = 90;

    /** `last_seen_at` is written at most this often, not on every beat. */
    public const PERSIST_EVERY = 60;

    public static function heartbeat(Staff $staff): void
    {
        if (!self::mayBeOnline($staff)) {
            return;
        }
        Cache::put(self::key($staff->id), now()->getTimestamp(), self::TTL);

        // One conditional update, so two tabs beating at once write it once. Not through the model: no audit row and no
        // `updated_at` for a heartbeat.
        Staff::query()->toBase()->where('id', $staff->id)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(self::PERSIST_EVERY)))
            ->update(['last_seen_at' => now()]);
    }

    public static function setStatus(Staff $staff, StaffPresenceStatus $status): void
    {
        Staff::query()->toBase()->where('id', $staff->id)->update(['presence_status' => $status->value]);
    }

    public static function status(Staff $staff): StaffPresenceStatus
    {
        return StaffPresenceStatus::tryFrom((string) $staff->presence_status) ?? StaffPresenceStatus::Available;
    }

    /**
     * The members who may enter the admin, online first, then by when they were last seen.
     *
     * @return list<array{staff: Staff, online: bool, status: StaffPresenceStatus, last_seen: ?Carbon}>
     */
    public static function roster(): array
    {
        $members = Staff::query()->where('active', true)->whereNotNull('role_id')->get();
        $beats = $members->isEmpty() ? [] : Cache::many($members->map(fn (Staff $staff) => self::key($staff->id))->all());

        $roster = $members->map(fn (Staff $staff) => [
            'staff' => $staff,
            'online' => ($beats[self::key($staff->id)] ?? null) !== null,
            'status' => self::status($staff),
            'last_seen' => $staff->last_seen_at,
        ])->all();

        usort($roster, fn (array $a, array $b) => [$b['online'], $b['last_seen']?->getTimestamp() ?? 0, $a['staff']->name]
            <=> [$a['online'], $a['last_seen']?->getTimestamp() ?? 0, $b['staff']->name]);

        return $roster;
    }

    /** @return list<array{staff: Staff, online: bool, status: StaffPresenceStatus, last_seen: ?Carbon}> */
    public static function online(): array
    {
        return array_values(array_filter(self::roster(), fn (array $member) => $member['online']));
    }

    /**
     * Only a member who is fully in the admin counts: active, with a role, and past the 2FA set-up when it is required
     * (StaffTwoFactor). One on the set-up page is not yet in (review of #362).
     */
    public static function mayBeOnline(Staff $staff): bool
    {
        return $staff->active && $staff->role_id !== null && StaffTwoFactor::satisfied($staff);
    }

    public static function canView(?Staff $staff): bool
    {
        // Past the mandatory 2FA set-up too, as the staff chat (review of #387).
        return $staff !== null && self::mayBeOnline($staff) && $staff->hasPermission('admin.staff_online.view');
    }

    private static function key(int $id): string
    {
        return 'staff-presence:' . $id;
    }
}

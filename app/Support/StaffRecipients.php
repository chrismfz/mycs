<?php

namespace App\Support;

use App\Models\Staff;
use Exception;

/**
 * Staff chosen in a setting to be told by email (the daily summary, system notices; owner 2026-11-13): who is listed
 * to choose from, and who is sent to. None chosen, or none of those chosen still active (and allowed): the System
 * Email Address, never silence.
 */
class StaffRecipients
{
    /** The setting of the staff who get the system notices (NotificationHelper::sendSystemEmailNotification). */
    public const SYSTEM_NOTICES = 'system_notice_recipients';

    /**
     * The addresses of the system notices: the staff chosen, else the System Email Address.
     *
     * @return list<string>
     */
    public static function systemNotices(): array
    {
        return self::chosen(self::SYSTEM_NOTICES) ?: self::systemAddress();
    }

    /**
     * The addresses of the active staff chosen in `$setting` (with `$permission`, when one is given), once each.
     *
     * @return list<string>
     */
    public static function chosen(string $setting, ?string $permission = null): array
    {
        $ids = self::ids($setting);
        if ($ids === []) {
            return [];
        }

        return Staff::query()->whereKey($ids)->where('active', true)->with('role')->orderBy('id')->get()
            ->filter(fn (Staff $staff) => trim((string) $staff->email) !== '' && ($permission === null || $staff->hasPermission($permission)))
            ->map(fn (Staff $staff) => trim((string) $staff->email))->unique()->values()->all();
    }

    /**
     * What the setting's field lists: active staff (with `$permission`), and any chosen who left or lost it, marked as
     * not sent to. Kept listed, or the field's «in» rule would refuse every save of the page and staff could not see
     * what to remove (code review of #689).
     *
     * @return array<int, string>
     */
    public static function options(string $setting, ?string $permission = null): array
    {
        try {
            $options = Staff::query()->where('active', true)->with('role')->orderBy('first_name')->get()
                ->filter(fn (Staff $staff) => trim((string) $staff->email) !== '' && ($permission === null || $staff->hasPermission($permission)))
                ->mapWithKeys(fn (Staff $staff) => [$staff->id => $staff->name . ' <' . $staff->email . '>'])->all();
            foreach (array_diff(self::ids($setting), array_keys($options)) as $id) {
                $staff = Staff::query()->find($id);
                $options[$id] = ($staff ? $staff->name . ' <' . $staff->email . '>' : "#{$id}") . ' (' . __('not sent to: inactive or no access') . ')';
            }

            return $options;
        } catch (Exception) {
            return []; // no database yet (install)
        }
    }

    /** @return list<string> */
    public static function systemAddress(): array
    {
        $system = trim((string) config('settings.system_email_address'));

        return $system === '' ? [] : [$system];
    }

    /** @return list<int> */
    private static function ids(string $setting): array
    {
        return array_values(array_filter(array_map('intval', (array) config("settings.{$setting}", []))));
    }
}

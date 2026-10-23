<?php

namespace App\Support;

use App\Models\Staff;

/**
 * Whether staff must have 2FA (Settings: «Require 2FA for staff», on by default; owner 2026-10-23: off for a while).
 * When it is off, a member without 2FA is fully in the admin; approving an MCP connector still needs it.
 */
final class StaffTwoFactor
{
    public static function required(): bool
    {
        return (bool) config('settings.staff_2fa_required', true);
    }

    /** Past what the admin asks of them: 2FA set up, or not required. */
    public static function satisfied(Staff $staff): bool
    {
        return !self::required() || $staff->hasTwoFactor();
    }
}

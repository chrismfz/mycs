<?php

namespace App\Support;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Who is doing this (docs/accounts.md, PR 1): the audits' causer and the money journal's actor.
 *
 * In the admin panel the staff member; in the client area while staff log in as a client, still the staff member
 * (AUTH-19: never the customer they look through); otherwise the customer, from the session or the API.
 */
class Actor
{
    public static function current(): Staff|User|null
    {
        $staff = self::guardUser('staff');
        if ($staff instanceof Staff && (Auth::getDefaultDriver() === 'staff' || self::impersonating())) {
            return $staff;
        }

        $user = self::guardUser('web') ?? self::guardUser('api');

        return $user instanceof User ? $user : null;
    }

    public static function impersonating(): bool
    {
        $request = request();

        return $request->hasSession() && $request->session()->has('impersonating');
    }

    private static function guardUser(string $guard): mixed
    {
        try {
            return Auth::guard($guard)->user();
        } catch (Throwable) {
            return null;
        }
    }
}

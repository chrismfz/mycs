<?php

namespace App\Support;

use App\Models\ApiKey;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Who is doing this (docs/accounts.md, PR 1): the audits' causer and the money journal's actor.
 *
 * In the admin panel the staff member; in the client area while staff log in as a client, still the staff member
 * (AUTH-19: never the customer they look through); in the admin API the key's staff member; otherwise the customer.
 */
class Actor
{
    public static function current(): Staff|User|null
    {
        // The admin API: the staff member whose key it is.
        $key = self::apiKey();
        if ($key?->staff instanceof Staff) {
            return $key->staff;
        }

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

    private static function apiKey(): ?ApiKey
    {
        try {
            $key = request()->attributes->get('api_key');
        } catch (Throwable) {
            return null;
        }

        return $key instanceof ApiKey ? $key : null;
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

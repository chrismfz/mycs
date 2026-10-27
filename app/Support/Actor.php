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

    /**
     * The admin permission (or all of a list) each of an account's permissions takes for staff in «Login as client» (SA-5):
     * they do there only what they may do in the admin, as the domains page already had it (R2).
     */
    public const STAFF_PERMISSION_AS = [
        // Seeing the account: as any staff in «Login as client» could.
        'products' => null,
        'domains' => null,
        'invoices' => null,
        'quotes' => null,
        'tickets' => null,
        'emails' => null,
        // Acting on it: what the same change needs in the admin.
        'profile' => 'admin.clients.update',
        'contacts' => 'admin.clients.update',
        'affiliates' => 'admin.clients.update',
        'manageproducts' => 'admin.services.update',
        // Into the service's panel: what the admin's «Log in to panel» takes, its own permission and the service's edit
        // right (ADV-2 of #465, ADV2-1 of its round 2), never less.
        'productsso' => ['admin.services.sso', 'admin.services.update'],
        'managedomains' => null, // the domain pages ask for each change's own admin permission (staffMay, R2)
        'orders' => 'admin.orders.create',
        // `chat` is never listed: staff do not chat as the customer (docs/livechat.md F9), so staffMayAs('chat') is false.
        // Paying with the customer's own money (a saved card, the vault, the balance): the admin's «mark paid».
        'pay' => 'admin.invoices.update',
    ];

    /**
     * Staff in «Login as client» may use this permission of the account (null: anything any login of it may): the
     * admin permission it takes (STAFF_PERMISSION_AS); never one with none listed. False when not impersonating.
     */
    public static function staffMayAs(?string $permission): bool
    {
        if (!self::impersonating()) {
            return false;
        }
        $staff = Auth::guard('staff')->user();
        if (!$staff instanceof Staff) {
            return false;
        }
        if ($permission === null) {
            return true;
        }
        if (!array_key_exists($permission, self::STAFF_PERMISSION_AS)) {
            return false; // none listed: not theirs to use
        }
        // One admin permission, or all of a list.
        foreach ((array) self::STAFF_PERMISSION_AS[$permission] as $admin) {
            if (!$staff->hasPermission($admin)) {
                return false;
            }
        }

        return true;
    }

    public static function impersonating(): bool
    {
        try {
            // The request's session, or the session store when the request carries none (Livewire's component calls).
            $session = request()->hasSession() ? request()->session() : app('session.store');

            return $session->has('impersonating');
        } catch (Throwable) {
            return false;
        }
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

<?php

namespace App\Support;

use App\Models\ApiKey;
use App\Models\ClientUser;
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

    /**
     * What giving a login each permission takes from staff, where «Login as client» needs less (STAFF_PERMISSION_AS
     * otherwise): there, staff only see the account and the domain pages check each change; on a login of their own,
     * nothing does. So the read permissions take the admin's view of the same records, and managing domains the admin's
     * registrar permissions (round 2 of #519, ADV-519-R2-1, R2-2).
     */
    public const GRANT_PERMISSION_AS = [
        'products' => 'admin.services.viewAny',
        'domains' => 'admin.domains.viewAny',
        // The login pays with the account's credit and saved cards too (round 3, ADV-519-R3-1): «pay» in Login as client.
        'invoices' => ['admin.invoices.viewAny', 'admin.invoices.update'],
        'quotes' => 'admin.invoices.viewAny',
        'tickets' => 'admin.tickets.viewAny',
        'emails' => ['admin.email_logs.viewAny', 'admin.email_logs.view'],
        // The SSL private keys come with managing the services (ADV-519-R3-2).
        'manageproducts' => ['admin.services.update', 'admin.services.ssl_keys'],
        // Auto renew, the renewal years and a renewal's invoice too (ADV-519-R3-3).
        'managedomains' => ['admin.domains.viewAny', 'admin.domains.update', 'admin.domains.manage', 'admin.domains.epp', 'admin.invoices.create'],
    ];

    /**
     * Staff may give a login these permissions of an account (null: all of them) over the ones it has ($had, null: all):
     * only the ones their role covers (GRANT_PERMISSION_AS, else what «Login as client» takes, STAFF_PERMISSION_AS), so a
     * login of their own on the account never takes them past their role (AUD3-1). Chat needs nothing.
     *
     * @param  list<string>|null  $permissions
     * @param  list<string>|null  $had
     */
    public static function staffMayGrant(?Staff $staff, ?array $permissions, ?array $had = []): bool
    {
        if ($staff === null) {
            return false;
        }
        $added = array_diff($permissions ?? ClientUser::PERMISSIONS, $had ?? ClientUser::PERMISSIONS);
        foreach ($added as $permission) {
            $needs = array_key_exists($permission, self::GRANT_PERMISSION_AS) ? self::GRANT_PERMISSION_AS[$permission] : (self::STAFF_PERMISSION_AS[$permission] ?? null);
            foreach ((array) $needs as $admin) {
                if (!$staff->hasPermission($admin)) {
                    return false;
                }
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

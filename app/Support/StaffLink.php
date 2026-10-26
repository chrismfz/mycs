<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * A link of the admin that does something when opened in a new tab (owner 2026-10-26: «Login as client», «Log in to
 * panel»): signed, for an hour, for the staff member it was made for. No other site can make one (a GET from a page
 * elsewhere is refused), and one left in a browser's history is of no use to anyone else.
 */
final class StaffLink
{
    public const MINUTES = 60;

    public const FORGED = 'forged';

    public const EXPIRED = 'expired';

    /** @param  array<string, mixed>  $parameters */
    public static function to(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(self::MINUTES), $parameters + ['by' => Auth::guard('staff')->id()]);
    }

    /**
     * Why the link is refused, null if it is good: FORGED (not ours, or another staff member's), EXPIRED (ours and
     * theirs, but older than an hour: a page left open; the caller sends them back to try again).
     */
    public static function refusal(Request $request): ?string
    {
        $staff = Auth::guard('staff')->id();
        if (!URL::hasCorrectSignature($request) || $staff === null || (string) $request->query('by') !== (string) $staff) {
            return self::FORGED;
        }

        return URL::signatureHasNotExpired($request) ? null : self::EXPIRED;
    }
}

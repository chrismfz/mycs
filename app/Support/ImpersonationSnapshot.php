<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

use function Livewire\on;

/**
 * A client area page acts only as the customer it was opened as (owner 2026-10-26). «Login as client» has its own tab
 * now, and the session has one: staff who log in as another client, or leave, change it for every tab. So each client
 * area component keeps whom staff were logged in as (in its signed snapshot), and an update from a page opened as
 * someone else is refused as expired (419: Livewire asks to reload), never run on the other customer's account.
 */
final class ImpersonationSnapshot
{
    private const MEMO = 'impersonating';

    public static function register(): void
    {
        on('dehydrate', function (Component $component, ComponentContext $context): void {
            // The admin's components: whom staff look through in another tab is not theirs.
            if (Auth::getDefaultDriver() !== 'staff') {
                $context->addMemo(self::MEMO, self::current());
            }
        });
        on('hydrate', function (Component $component, array $memo, ComponentContext $context): void {
            if (array_key_exists(self::MEMO, $memo) && $memo[self::MEMO] !== self::current()) {
                abort(419);
            }
        });
    }

    /** The customer staff are logged in as (`impersonating`, its account too), null when they are not. */
    private static function current(): ?string
    {
        if (!Actor::impersonating()) {
            return null;
        }
        $session = request()->hasSession() ? request()->session() : app('session.store');

        return $session->get('impersonating') . '|' . $session->get(CurrentClient::IMPERSONATING_KEY);
    }
}

<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The account the client area works on (docs/accounts.md, PR 2a), as WHMCS 8 keeps the client of the session: one
 * of the accounts the logged-in user has a live grant on. Kept in the session; a stale or foreign id is never taken,
 * the user's default account is. During «Login as client» it is the customer's own account, never the session's.
 */
class CurrentClient
{
    public const SESSION_KEY = 'client_id';

    private ?Client $resolved = null;

    /** Who it was resolved for: the user, the session's choice and «Login as client» (a change of any resolves again). */
    private ?string $resolvedFor = null;

    public function get(): ?Client
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof User) {
            return null;
        }
        $session = $this->session();
        $impersonating = Actor::impersonating();
        $wanted = $impersonating ? null : $session?->get(self::SESSION_KEY);
        $key = $user->id . '|' . $wanted . '|' . (int) $impersonating;
        if ($this->resolvedFor === $key && $this->resolved !== null) {
            return $this->resolved;
        }

        $client = null;
        if ($wanted !== null) {
            $client = $user->clients()->whereKey((int) $wanted)->first();
        }
        $client ??= self::defaultFor($user);
        if ($client !== null && !$impersonating) {
            $session?->put(self::SESSION_KEY, $client->id);
        }

        $this->resolvedFor = $user->id . '|' . ($impersonating ? '' : $client?->id) . '|' . (int) $impersonating;

        return $this->resolved = $client;
    }

    /** The current account of the client area (null for a guest or a login without any account). */
    public static function model(): ?Client
    {
        return app(self::class)->get();
    }

    /** Its id; 0 when there is none, so a query by it matches nothing. */
    public static function id(): int
    {
        return self::model()->id ?? 0;
    }

    /** Switch to another of the user's accounts (2b's account picker); false when it is not theirs. */
    public function switchTo(int $clientId): bool
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof User || !$user->clients()->whereKey($clientId)->exists()) {
            return false;
        }
        $this->session()?->put(self::SESSION_KEY, $clientId);
        $this->forget();

        return true;
    }

    public function forget(): void
    {
        $this->resolved = null;
        $this->resolvedFor = null;
    }

    /** The account a user works on by default: the one they own, else the first they were given. */
    public static function defaultFor(User $user): ?Client
    {
        return $user->clients()->orderByDesc('client_user.owner')->orderBy('clients.id')->first();
    }

    private function session(): ?Session
    {
        try {
            return request()->hasSession() ? request()->session() : app('session.store');
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The account the client area works on (docs/accounts.md, PR 2a), as WHMCS 8 keeps the client of the session: one
 * of the accounts the logged-in user has a live grant on. Kept in the session; a stale or foreign id is never taken,
 * the user's default account is. During «Login as client» it is the account staff opened (when the customer has a
 * live grant on it) or the customer's default one, never the one the customer's session chose.
 */
class CurrentClient
{
    public const SESSION_KEY = 'client_id';

    /** The account staff opened with «Login as client» (taken only while the customer has a live grant on it). */
    public const IMPERSONATING_KEY = 'impersonating_client_id';

    private ?Client $resolved = null;

    /** Who it was resolved for: the user, the session's choice and «Login as client» (a change of any resolves again). */
    private ?string $resolvedFor = null;

    private ?ClientUser $grant = null;

    private ?string $grantFor = null;

    public function get(): ?Client
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof User) {
            return null;
        }
        $session = $this->session();
        $impersonating = Actor::impersonating();
        $wanted = $session?->get($impersonating ? self::IMPERSONATING_KEY : self::SESSION_KEY);
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

    /**
     * Whether the login may use this area of the current account (WHMCS permission names, PR 2b): the owner and a
     * grant without a list have them all; staff in «Login as client» act as the owner (full control).
     */
    public static function allows(string $permission): bool
    {
        $client = self::model();
        $user = Auth::guard('web')->user();
        if ($client === null || !$user instanceof User) {
            return false;
        }
        if (Actor::impersonating()) {
            return true;
        }

        return (bool) app(self::class)->grantOf($user, $client)?->allows($permission);
    }

    /** The login's live grant on the account, once per request (nav, widgets and guards ask often). */
    private function grantOf(User $user, Client $client): ?ClientUser
    {
        $key = $user->id . '|' . $client->id;
        if ($this->grantFor !== $key) {
            $this->grant = $client->grants()->where('user_id', $user->id)->whereNull('revoked_at')->first();
            $this->grantFor = $key;
        }

        return $this->grant;
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
        $this->grant = null;
        $this->grantFor = null;
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

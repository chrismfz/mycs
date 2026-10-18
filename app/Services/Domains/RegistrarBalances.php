<?php

namespace App\Services\Domains;

use App\Contracts\ReadsAccountBalance;
use App\Helpers\NotificationHelper;
use App\Models\DomainRegistrarConnection;
use App\Support\Redact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What is left with each registrar (G8-1, owner 2026-10-19: «Providers credit» on the dashboard, as in WHMCS). Read
 * hourly by `domains:balances` and kept on the connection: a page shows what was read, never calls a registrar. Staff
 * are told by mail when a balance falls under its connection's «low balance», when the registry suspends (or will
 * suspend) chargeable actions, or when it could not be read for a day; once for each such state.
 */
final class RegistrarBalances
{
    /** A balance read longer ago than this is stale (the read keeps failing). */
    public const STALE_HOURS = 24;

    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /** @return Collection<int, DomainRegistrarConnection> the usable connections whose registrar tells its balance */
    public function connections(): Collection
    {
        return DomainRegistrarConnection::query()->orderBy('id')->get()
            ->filter(fn (DomainRegistrarConnection $connection) => $connection->isUsable() && $this->reads($connection))->values();
    }

    public function reads(DomainRegistrarConnection $connection): bool
    {
        try {
            return $this->factory->for($connection) instanceof ReadsAccountBalance;
        } catch (Throwable) {
            return false; // a registrar no longer installed
        }
    }

    /**
     * Read it now and keep it; a failed read keeps the last balance with its error (and when the errors began). Kept
     * first, then staff told, if it is news (review 3 of #206: a mail that fails loses nothing).
     */
    public function refresh(DomainRegistrarConnection $connection): void
    {
        $kept = $connection->balance ?? [];
        try {
            $registrar = $this->factory->for($connection);
            if (!$registrar instanceof ReadsAccountBalance) {
                return;
            }
            $kept = $registrar->accountBalance($this->factory->credentialsFor($connection))->toArray()
                + ['read_at' => now()->toIso8601String(), 'error' => null, 'error_at' => null, 'failing_since' => null, 'told' => $kept['told'] ?? null];
        } catch (Throwable $e) {
            // Its own credentials hidden too (review 7): the text is kept, mailed and shown.
            $error = Redact::values(Redact::text($e->getMessage()), array_filter($connection->credentials(), 'is_string'));
            $kept = ['error' => mb_substr($error, 0, 300), 'error_at' => now()->toIso8601String(),
                'failing_since' => $kept['failing_since'] ?? now()->toIso8601String()] + $kept;
        }
        $this->keep($connection, $kept);
        $this->keep($connection, $this->tell($connection, $kept));
    }

    /**
     * What is wrong with it, for staff, or null: suspended, to be suspended, chargeable actions not allowed, under its low
     * balance, or not read for a day. Its kind is a code, its text in the reader's language (review 1 of #206: told once
     * by its code, whoever read it in whatever language).
     *
     * @param  array<string, mixed>|null  $balance
     * @return array{kind: string, text: string}|null
     */
    public static function problem(DomainRegistrarConnection $connection, ?array $balance = null): ?array
    {
        $balance ??= $connection->balance;
        if (!is_array($balance) || $balance === []) {
            return null; // never read yet
        }
        $readAt = is_string($balance['read_at'] ?? null) ? Carbon::parse($balance['read_at']) : null;
        $failingSince = is_string($balance['failing_since'] ?? null) ? Carbon::parse($balance['failing_since']) : null;
        // Never read well: since its errors began, not «a day» from the first one (review 2).
        $since = $readAt ?? $failingSince;
        if ($since !== null && $since->lt(now()->subHours(self::STALE_HOURS))) {
            return ['kind' => 'stale', 'text' => __('Not read for :hours hours: :error', ['hours' => self::STALE_HOURS, 'error' => $balance['error'] ?? '—'])];
        }
        if ($readAt === null) {
            return $failingSince !== null ? ['kind' => 'unread', 'text' => __('Could not be read yet: :error', ['error' => $balance['error'] ?? '—'])] : null;
        }
        $date = fn (string $at) => Carbon::parse($at)->timezone(config('app.timezone'))->format('d/m/Y'); // review 8
        if (is_string($balance['suspended_on'] ?? null) && Carbon::parse($balance['suspended_on'])->lte(now())) {
            return ['kind' => 'suspended:' . $date($balance['suspended_on']), 'text' => __('Suspended since :date: no registrations or renewals', ['date' => $date($balance['suspended_on'])])];
        }
        if (is_string($balance['suspends_on'] ?? null)) {
            return ['kind' => 'suspends:' . $date($balance['suspends_on']), 'text' => Carbon::parse($balance['suspends_on'])->isFuture()
                ? __('To be suspended on :date', ['date' => $date($balance['suspends_on'])])
                : __('Due to be suspended since :date', ['date' => $date($balance['suspends_on'])])];
        }
        if (($balance['charges_allowed'] ?? null) === false) {
            return ['kind' => 'charges', 'text' => __('Chargeable actions are not allowed')];
        }
        if ($connection->low_balance !== null && is_string($balance['amount'] ?? null)
            && bccomp($balance['amount'], (string) $connection->low_balance, 2) < 0) {
            return ['kind' => 'low', 'text' => __('Under :low', ['low' => $connection->low_balance])];
        }

        return null;
    }

    /** Its text, for a page. */
    public static function problemText(DomainRegistrarConnection $connection): ?string
    {
        return self::problem($connection)['text'] ?? null;
    }

    /** @param  array<string, mixed>  $balance */
    private function keep(DomainRegistrarConnection $connection, array $balance): void
    {
        // Not through the model: no audit and no updated_at for a read (its $auditExclude is the second guard).
        DomainRegistrarConnection::query()->whereKey($connection->id)->update(['balance' => json_encode($balance)]);
        $connection->forceFill(['balance' => $balance])->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed> with what staff were last told
     */
    private function tell(DomainRegistrarConnection $connection, array $balance): array
    {
        $problem = self::problem($connection, $balance);
        $kind = $problem['kind'] ?? null;
        if ($kind === ($balance['told'] ?? null)) {
            return $balance;
        }
        if ($problem !== null) {
            // Not sent (mail off, no system address): not told, so told once mail is on (review 4).
            if (config('settings.mail_disable') || !config('settings.system_email_address')) {
                return $balance;
            }
            $amount = e(($balance['amount'] ?? '—') . ' ' . ($balance['currency'] ?? ''));
            NotificationHelper::sendSystemEmailNotification(__('Registrar credit: :label', ['label' => $connection->label]), e($connection->label) . ': '
                . e($problem['text']) . '<br>' . __('Balance') . ': ' . $amount);
        }

        return ['told' => $kind] + $balance;
    }
}

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

    /** Read it now and keep it; a failed read keeps the last balance with its error. Then staff told, if it is news. */
    public function refresh(DomainRegistrarConnection $connection): void
    {
        $kept = $connection->balance ?? [];
        try {
            $registrar = $this->factory->for($connection);
            if (!$registrar instanceof ReadsAccountBalance) {
                return;
            }
            $kept = $registrar->accountBalance($this->factory->credentialsFor($connection))->toArray()
                + ['read_at' => now()->toIso8601String(), 'error' => null, 'error_at' => null, 'told' => $kept['told'] ?? null];
        } catch (Throwable $e) {
            $kept = ['error' => mb_substr(Redact::text($e->getMessage()), 0, 300), 'error_at' => now()->toIso8601String()] + $kept;
        }
        $kept = $this->tell($connection, $kept);
        // Not through the model: no audit and no updated_at for a read (its $auditExclude is the second guard).
        DomainRegistrarConnection::query()->whereKey($connection->id)->update(['balance' => json_encode($kept)]);
        $connection->forceFill(['balance' => $kept])->syncOriginal();
    }

    /**
     * What is wrong with it, for staff, or null: suspended, to be suspended, chargeable actions not allowed, under its low
     * balance, or not read for a day.
     *
     * @param  array<string, mixed>|null  $balance
     */
    public static function problem(DomainRegistrarConnection $connection, ?array $balance = null): ?string
    {
        $balance ??= $connection->balance;
        if (!is_array($balance) || $balance === []) {
            return null; // never read yet
        }
        $readAt = is_string($balance['read_at'] ?? null) ? Carbon::parse($balance['read_at']) : null;
        if ($readAt === null || $readAt->lt(now()->subHours(self::STALE_HOURS))) {
            return __('Not read for :hours hours: :error', ['hours' => self::STALE_HOURS, 'error' => $balance['error'] ?? '—']);
        }
        if (is_string($balance['suspended_on'] ?? null) && Carbon::parse($balance['suspended_on'])->lte(now())) {
            return __('Suspended since :date: no registrations or renewals', ['date' => Carbon::parse($balance['suspended_on'])->format('d/m/Y')]);
        }
        if (is_string($balance['suspends_on'] ?? null)) {
            return __('To be suspended on :date', ['date' => Carbon::parse($balance['suspends_on'])->format('d/m/Y')]);
        }
        if (($balance['charges_allowed'] ?? null) === false) {
            return __('Chargeable actions are not allowed');
        }
        if ($connection->low_balance !== null && is_string($balance['amount'] ?? null)
            && bccomp($balance['amount'], (string) $connection->low_balance, 2) < 0) {
            return __('Under :low', ['low' => $connection->low_balance]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed> with what staff were last told
     */
    private function tell(DomainRegistrarConnection $connection, array $balance): array
    {
        $problem = self::problem($connection, $balance);
        // Its kind, not its words: a balance going down under the line is one state, told once.
        $kind = $problem === null ? null : strtok($problem, ':');
        if ($kind === ($balance['told'] ?? null)) {
            return $balance;
        }
        if ($problem !== null) {
            $amount = e(($balance['amount'] ?? '—') . ' ' . ($balance['currency'] ?? ''));
            NotificationHelper::sendSystemEmailNotification(__('Registrar credit: :label', ['label' => $connection->label]), e($connection->label) . ': '
                . e($problem) . '<br>' . __('Balance') . ': ' . $amount);
        }

        return ['told' => $kind] + $balance;
    }
}

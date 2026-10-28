<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of a bank account, from the bank's alert email (bank:alerts).
 *
 * @property string $amount
 * @property string|null $balance
 * @property Carbon|null $executed_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $matched_at
 */
class BankMovement extends Model
{
    public const PENDING = 'pending';

    public const ACKNOWLEDGED = 'acknowledged';

    public const IGNORED = 'ignored';

    public const HISTORY = 'history';

    public const UNVERIFIED = 'unverified';

    /** Matched to its invoice and recorded on it, automatically. */
    public const MATCHED = 'matched';

    public const BANKS = ['eurobank' => 'Eurobank', 'piraeus' => 'Πειραιώς'];

    protected $fillable = ['bank', 'direction', 'amount', 'balance', 'reason', 'executed_at', 'message_key', 'message_id', 'folder', 'account',
        'authentic', 'authenticity', 'status', 'acknowledged_by', 'acknowledged_at', 'invoice_id', 'matched_at', 'match_note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance' => 'decimal:2', 'executed_at' => 'datetime', 'acknowledged_at' => 'datetime', 'authentic' => 'boolean', 'matched_at' => 'datetime'];
    }

    /** @return BelongsTo<Staff, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'acknowledged_by');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * What its reference may name an invoice by: «2026-123» numbers and runs of 3 to 9 digits, never part of a date
     * («2026-10-25», «22.10.2026», «22/10/26»: reviews of #458).
     *
     * @return list<string>
     */
    public function invoiceTokens(): array
    {
        $reason = (string) preg_replace('/(?<![\d])(\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2}|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})(?![\d])/', ' ', (string) $this->reason);
        preg_match_all('/(?<![\d-])\d{4}-\d{1,8}(?![\d-])|(?<![\d-])\d{3,9}(?![\d-])/', $reason, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * The invoices its reference names, of any status and currency: by their number; by their id only when they have
     * none, and only by a plain number («0110», a group of an IBAN, names no id: reviews of #458). An id is no number the
     * customer was given: never matched against another instance's numbering.
     *
     * @return Builder<Invoice>
     */
    public function namedInvoices(): Builder
    {
        $tokens = $this->invoiceTokens();
        $ids = array_values(array_filter($tokens, fn (string $t) => preg_match('/^[1-9]\d*$/', $t) === 1));

        return Invoice::query()->when($tokens === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->where(fn (Builder $q) => $q->whereIn('number', $tokens)
                ->orWhere(fn (Builder $q) => $q->where(fn ($n) => $n->whereNull('number')->orWhere('number', ''))->whereIn('id', $ids)));
    }

    public function scopeCredits(Builder $query): void
    {
        $query->where('direction', 'credit');
    }

    public function bankName(): string
    {
        return self::BANKS[$this->bank] ?? $this->bank;
    }

    /**
     * Each bank's balance: the sum of its accounts', each as its latest authentic alert says it (two accounts at
     * Piraeus, owner 2026-10-28: the bank's latest alert alone showed one of them), with the latest of their when.
     *
     * @return array<string, array{balance: string, at: Carbon|null, accounts: array<string, string>}>
     */
    public static function balances(): array
    {
        $balances = [];
        foreach (array_keys(self::BANKS) as $bank) {
            $accounts = self::query()->where('bank', $bank)->where('authentic', true)->whereNotNull('balance')
                ->orderByDesc('executed_at')->orderByDesc('id')->get(['account', 'balance', 'executed_at'])
                ->unique(fn (self $m) => mb_strtoupper(trim((string) $m->account)));
            if ($accounts->isEmpty()) {
                continue;
            }
            $balances[$bank] = [
                'balance' => $accounts->reduce(fn (string $sum, self $m) => bcadd($sum, (string) $m->balance, 2), '0.00'),
                'at' => $accounts->first()->executed_at,
                'accounts' => $accounts->mapWithKeys(fn (self $m) => [(string) $m->account => (string) $m->balance])->all(),
            ];
        }

        return $balances;
    }
}

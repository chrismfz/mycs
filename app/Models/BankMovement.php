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
     * What its reference may name an invoice by: «2026-123» numbers and runs of 3 to 9 digits.
     *
     * @return list<string>
     */
    public function invoiceTokens(): array
    {
        preg_match_all('/(?<![\d-])\d{4}-\d{1,8}(?![\d])|(?<![\d-])\d{3,9}(?![\d-])/', (string) $this->reason, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * The invoices its reference names: by their number; by their id only when they have none (an id is no number the
     * customer was given: never matched against another instance's numbering).
     *
     * @return Builder<Invoice>
     */
    public function namedInvoices(): Builder
    {
        $tokens = $this->invoiceTokens();

        return Invoice::query()->when($tokens === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->where(fn (Builder $q) => $q->whereIn('number', $tokens)
                ->orWhere(fn (Builder $q) => $q->where(fn ($n) => $n->whereNull('number')->orWhere('number', ''))->whereIn('id', array_filter($tokens, 'ctype_digit'))));
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
     * Each bank's balance as its latest authentic alert says it, with when.
     *
     * @return array<string, array{balance: string, at: Carbon|null}>
     */
    public static function balances(): array
    {
        $balances = [];
        foreach (array_keys(self::BANKS) as $bank) {
            $latest = self::query()->where('bank', $bank)->where('authentic', true)->whereNotNull('balance')
                ->orderByDesc('executed_at')->orderByDesc('id')->first();
            if ($latest !== null) {
                $balances[$bank] = ['balance' => (string) $latest->balance, 'at' => $latest->executed_at];
            }
        }

        return $balances;
    }
}

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
 */
class BankMovement extends Model
{
    public const PENDING = 'pending';

    public const ACKNOWLEDGED = 'acknowledged';

    public const IGNORED = 'ignored';

    public const HISTORY = 'history';

    public const BANKS = ['eurobank' => 'Eurobank', 'piraeus' => 'Πειραιώς'];

    protected $fillable = ['bank', 'direction', 'amount', 'balance', 'reason', 'executed_at', 'message_key', 'message_id', 'folder',
        'authentic', 'authenticity', 'status', 'acknowledged_by', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance' => 'decimal:2', 'executed_at' => 'datetime', 'acknowledged_at' => 'datetime', 'authentic' => 'boolean'];
    }

    /** @return BelongsTo<Staff, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'acknowledged_by');
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

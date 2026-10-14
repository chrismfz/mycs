<?php

namespace App\Models;

use App\Enums\MoneyEventKind;
use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One money movement (docs/money-trail.md §1). Append-only: written only by App\Services\Money\MoneyTrail, inside
 * the transaction of the movement itself, and never updated or deleted. A mistake is undone by a reversal.
 *
 * @property int|null $client_id
 */
class MoneyEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'kind',
        'amount',
        'currency_code',
        'client_id',
        'invoice_id',
        'invoice_transaction_id',
        'credit_balance_after',
        'source',
        'cause',
        'external_reference',
        'group_reference',
        'actor_type',
        'actor_id',
        'reason',
        'reverses_id',
        'occurred_at',
    ];

    protected $casts = [
        'kind' => MoneyEventKind::class,
        'amount' => 'decimal:2',
        'credit_balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /**
     * Mass updates and deletes are refused as well (they skip the model events below).
     *
     * @return AppendOnlyBuilder<MoneyEvent>
     */
    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        /** @var AppendOnlyBuilder<MoneyEvent> $builder */
        $builder = new AppendOnlyBuilder($query);

        return $builder;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Money events are append-only: write a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Money events are append-only: write a reversal instead.'));
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoiceTransaction, $this> */
    public function invoiceTransaction(): BelongsTo
    {
        return $this->belongsTo(InvoiceTransaction::class);
    }

    /** @return HasOne<MoneyEventNote, $this> */
    public function note(): HasOne
    {
        return $this->hasOne(MoneyEventNote::class);
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<MoneyEvent, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(MoneyEvent::class, 'reverses_id');
    }

    /** @return HasOne<MoneyEvent, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(MoneyEvent::class, 'reverses_id');
    }

    /**
     * What this event did to the customer's account credit, in cents (+ in, - out); a reversal undoes its event.
     */
    public function creditEffectCents(): int
    {
        $cents = (int) round((float) $this->amount * 100);

        return match ($this->kind) {
            MoneyEventKind::CreditIn => $cents,
            MoneyEventKind::CreditOut => -$cents,
            MoneyEventKind::Reversal => -($this->reverses?->creditEffectCents() ?? 0),
            default => 0,
        };
    }
}

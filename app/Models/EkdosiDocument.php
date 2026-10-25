<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A fiscal document Ekdosi issued from an invoice, as it wrote it back (docs/ekdosi-bridge.md).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $mark
 * @property string|null $code
 * @property string $state
 * @property string|null $pdf_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EkdosiDocument extends Model implements Auditable
{
    use Traits\Auditable;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['invoice_id', 'mark', 'code', 'state', 'pdf_url'];

    /**
     * Whether Ekdosi issued an active fiscal document for this invoice: its lines then stay as they are (no merge, split,
     * move, or line changed by staff, the API or the app), or they would reach no document, or a second one (#459).
     * Locking (in a transaction): a write-back committed meanwhile is seen, and one coming waits (R2-8 of #459).
     */
    public static function activeOn(mixed $invoiceId, bool $lock = false): bool
    {
        if ($invoiceId === null) {
            return false;
        }
        $query = static::query()->where('invoice_id', $invoiceId)->where('state', self::ACTIVE);

        return ($lock ? $query->lockForUpdate() : $query)->exists();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

<?php

namespace App\Models;

use App\Services\Money\MoneyTrail;
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

    /**
     * Paid in WHMCS and imported so (REV-780-7, by «issued is final»): its fiscal document was issued through WHMCS before
     * the cut-over, and those marks are not imported, so no row here says so. Its lines and notes stay as an invoice's
     * with an active document do. The bridge keeps no feed start date (Ekdosi sends its own `since`), so the definition
     * is: a paid invoice, not one staff marked paid by hand, whose money in the journal is all the importer's
     * (source «import») — none that MyCS recorded. One paid here, or part paid in WHMCS and settled here, is Ekdosi's to
     * issue and is seen by its document.
     */
    public static function issuedBeforeCutOver(mixed $invoiceId): bool
    {
        if ($invoiceId === null || !Invoice::query()->whereKey($invoiceId)->where('status', Invoice::STATUS_PAID)->whereNull('paid_by_staff_at')->exists()) {
            return false;
        }
        $money = MoneyEvent::query()->where('invoice_id', $invoiceId);

        return (clone $money)->where('source', MoneyTrail::SOURCE_IMPORT)->exists()
            && !(clone $money)->where('source', '!=', MoneyTrail::SOURCE_IMPORT)->exists();
    }

    /** What staff are told when they try to change one (lines, notes). */
    public static function cutOverRefusal(): string
    {
        return __('This invoice was paid before the cut-over: its document was issued before the cut-over. A correction is made in Ekdosi.');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

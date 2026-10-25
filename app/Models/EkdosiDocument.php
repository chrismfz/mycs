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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

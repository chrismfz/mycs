<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paid period of a bulk license, closed once over (docs/zonecloud-bulk-license.md §6.0): its distinct IPs, what that
 * cost, what was billed for it in advance, and the difference (billed later, or credited). Never changed once written.
 *
 * @property int $id
 * @property int $license_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int|null $invoice_item_id
 * @property int $ips
 * @property string $actual
 * @property string $billed
 * @property string $adjustment
 * @property int|null $adjustment_item_id
 * @property string $tiers
 * @property Carbon $closed_at
 */
class LicenseUsagePeriod extends Model
{
    protected $fillable = ['license_id', 'period_start', 'period_end', 'invoice_item_id', 'ips', 'actual', 'billed', 'adjustment',
        'adjustment_item_id', 'tiers', 'closed_at'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'ips' => 'integer',
        'actual' => 'decimal:2',
        'billed' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /** @return BelongsTo<InvoiceItem, $this> */
    public function adjustmentItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'adjustment_item_id');
    }
}

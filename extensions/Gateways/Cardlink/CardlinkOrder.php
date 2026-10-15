<?php

namespace MyCS\Extensions\Gateways\Cardlink;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An order sent to the Cardlink vPOS (PR H2a): its return pays this invoice (or this Mass Pay group), of this customer,
 * and nothing else.
 *
 * @property int $id
 * @property string $order_id
 * @property int $invoice_id
 * @property int $client_id
 * @property string|null $payment_group
 * @property string $amount
 * @property string $currency_code
 * @property string|null $locale
 * @property string|null $status
 * @property string|null $tx_id
 * @property string|null $pay_method
 * @property Carbon|null $returned_at
 */
class CardlinkOrder extends Model
{
    public const STATUS_CAPTURED = 'CAPTURED';

    protected $table = 'cardlink_orders';

    protected $fillable = ['order_id', 'invoice_id', 'client_id', 'payment_group', 'amount', 'currency_code', 'locale', 'status', 'tx_id', 'pay_method', 'returned_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'returned_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

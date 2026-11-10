<?php

namespace App\Http\Resources;

use App\Models\AdjustmentNote;
use TiMacDonald\JsonApi\JsonApiResource;

/**
 * @mixin AdjustmentNote
 */
class AdjustmentNoteResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'invoice_id',
        'number',
        // `credit` (a negative amount) or `debit`: it follows the amount's sign.
        'type',
        'amount',
        'description',
        'status',
        // A refund's or a cancellation's own note: never changed by hand.
        'is_refund',
        'is_cancellation',
        'created_at',
        'updated_at',
    ];
}

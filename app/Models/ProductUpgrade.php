<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ProductUpgrade extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $guarded = [];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function upgrade(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'upgrade_id');
    }
}

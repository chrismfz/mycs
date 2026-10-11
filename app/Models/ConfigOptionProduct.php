<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ConfigOptionProduct extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'config_option_id',
        'product_id',
    ];

    public $timestamps = false;

    /**
     * Get the option of the product.
     *
     * @return BelongsTo<ConfigOption, $this>
     */
    public function configOption(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class);
    }

    /**
     * Get the product of the option.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

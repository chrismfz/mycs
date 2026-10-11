<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ServiceConfig extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'service_id',
        'config_option_id',
        'config_value_id',
    ];

    /**
     * Get the service that owns the service config.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the config option that owns the service config.
     *
     * @return BelongsTo<ConfigOption, $this>
     */
    public function configOption(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class);
    }

    /**
     * Get the config value that owns the service config.
     *
     * @return BelongsTo<ConfigOption, $this>
     */
    public function configValue(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class, 'config_value_id');
    }
}

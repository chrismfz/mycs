<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delegation nameserver a domain uses (Πυλώνας A / A1) — NOT a glue/child
 * host (domain_hosts, A3). docs/domains/README.md §3.5.
 *
 * @property string $host
 * @property int $sort_order
 */
class DomainNameserver extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'host',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** Normalized at the model (create AND edit): hosts compare case-insensitively at A2 sync. */
    protected function host(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}

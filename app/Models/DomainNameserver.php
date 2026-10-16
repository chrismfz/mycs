<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $host
 * @property int $sort
 */
class DomainNameserver extends Model
{
    protected $fillable = ['domain_id', 'host', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}

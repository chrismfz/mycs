<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service's usage as its server last told it (services:usage).
 *
 * @property int $service_id
 * @property int|null $disk_used_mb
 * @property int|null $disk_limit_mb
 * @property int|null $bandwidth_used_mb
 * @property int|null $bandwidth_limit_mb
 * @property int|null $accounts
 * @property int|null $accounts_limit
 * @property string $accounts_kind accounts (a reseller's) or mailboxes
 * @property Carbon $read_at
 */
class ServiceUsage extends Model
{
    protected $fillable = ['service_id', 'disk_used_mb', 'disk_limit_mb', 'bandwidth_used_mb', 'bandwidth_limit_mb', 'accounts', 'accounts_limit', 'accounts_kind', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'disk_used_mb' => 'integer', 'disk_limit_mb' => 'integer', 'bandwidth_used_mb' => 'integer',
            'bandwidth_limit_mb' => 'integer', 'accounts' => 'integer', 'accounts_limit' => 'integer'];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** «793 MB», «20 GB»; null: «—». */
    public static function size(?int $mb): string
    {
        if ($mb === null) {
            return '—';
        }

        // The reader's decimal mark (code review 9): «1,5 GB» in Greek, «1.5 GB» in English.
        [$decimal, $thousands] = str_starts_with(app()->getLocale(), 'el') ? [',', '.'] : ['.', ','];

        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1, $decimal, $thousands), '0'), $decimal) . ' GB' : $mb . ' MB';
    }

    /** Its share of the limit, 0–100; null: no limit. */
    public static function percent(?int $used, ?int $limit): ?int
    {
        return $limit === null || $limit <= 0 || $used === null ? null : (int) min(100, round($used * 100 / $limit));
    }
}

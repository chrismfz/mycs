<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A domain an account hosts, as its server last told it (services:usage). Information only (owner 2026-10-22): never
 * read by an order, a creation or any check: an addon here (nac.gr on cdnet.gr's account) may get its own hosting.
 *
 * @property int $service_id
 * @property string $domain
 * @property string $type main, addon, parked or sub
 * @property string|null $account
 * @property Carbon $read_at
 */
class ServiceHostedDomain extends Model
{
    public const TYPES = ['main', 'addon', 'parked', 'sub'];

    protected $fillable = ['service_id', 'domain', 'type', 'account', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** As people read it: an IDN in Unicode (the server keeps it in punycode: code review L4 of #320). */
    public function displayName(): string
    {
        $unicode = str_contains($this->domain, 'xn--') ? idn_to_utf8($this->domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

        return is_string($unicode) && $unicode !== '' ? $unicode : $this->domain;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registrar WRITE command's audit row (Πυλώνας A / A3) — request/response/
 * outcome per state-changing call (renew/register/transfer/…), README §9 «API
 * history». `status` = ok | adopted (the §6.6 already-renewed guard fired —
 * no registrar call was made) | failed. `request`/`response` are SANITIZED
 * snapshots — credentials never land here.
 *
 * @property int $id
 * @property int|null $domain_id
 * @property int|null $invoice_id
 * @property string $action
 * @property string $status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error
 */
class DomainRegistrarLog extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'domain_id',
        'registrar_connection_id',
        'action',
        'status',
        'request',
        'response',
        'error',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
        ];
    }

    /**
     * OK renewals not yet tied to any invoice — the pool both §6.6 adopt legs
     * draw from (intent-match consume + date-adopt stamping). ONE filter so
     * the two legs can never drift apart on what «unconsumed» means.
     */
    public function scopeUnconsumedOkRenewals($query, Domain $domain)
    {
        return $query
            ->where('domain_id', $domain->id)
            ->where('action', 'renew')
            ->where('status', self::STATUS_OK)
            ->whereNull('invoice_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'registrar_connection_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

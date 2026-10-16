<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call to a registrar (docs/domains.md D-17): what was asked and answered, its outcome, and the invoice it was for
 * (a renewal runs once per invoice). Secrets never reach it: requests are logged without credentials or EPP codes.
 *
 * @property int $id
 * @property int|null $domain_id
 * @property int|null $registrar_connection_id
 * @property string $action
 * @property string $status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error
 * @property int|null $invoice_id
 */
class DomainRegistrarLog extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['domain_id', 'registrar_connection_id', 'action', 'status', 'request', 'response', 'error', 'invoice_id'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array'];
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return BelongsTo<DomainRegistrarConnection, $this> */
    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

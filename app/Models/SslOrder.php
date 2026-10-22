<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service's certificate at Openprovider (extension OpenproviderSsl): its order and what Openprovider last told of it.
 *
 * @property int $id
 * @property int $service_id
 * @property int $connection_id
 * @property int|null $order_id
 * @property int $product_id
 * @property int $period
 * @property string|null $common_name
 * @property list<string>|null $host_names
 * @property string|null $csr
 * @property string|null $validation_method
 * @property string|null $approver_email
 * @property string|null $organization_handle
 * @property string|null $status
 * @property Carbon|null $requested_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $reissue_at
 * @property Carbon|null $order_ends_at
 * @property string|null $certificate
 * @property string|null $intermediate
 * @property array<string, mixed>|null $validation
 * @property Carbon|null $synced_at
 * @property string|null $sync_error
 */
class SslOrder extends Model
{
    /** Validation methods Openprovider takes: an approver's email, a DNS record, a file over HTTPS. */
    public const METHODS = ['email', 'dns', 'https'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'host_names' => 'array',
            'validation' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'reissue_at' => 'datetime',
            'order_ends_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<DomainRegistrarConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'connection_id');
    }

    /** Waiting for its CSR: no order asked for yet. */
    public function awaitingConfiguration(): bool
    {
        return $this->order_id === null && $this->requested_at === null;
    }

    /** Issued: Openprovider gave its certificate. */
    public function issued(): bool
    {
        return trim((string) $this->certificate) !== '';
    }
}

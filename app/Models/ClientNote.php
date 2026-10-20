<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A note on an account, staff only (as WHMCS's client «Notes»): who wrote it and when, and whether it is sticky (shown on
 * the client's Summary). Never reaches the client area or a template (not in TemplateRenderer's READS).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $staff_id
 * @property string $body
 * @property bool $sticky
 */
class ClientNote extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['client_id', 'staff_id', 'body', 'sticky'];

    protected $casts = ['sticky' => 'boolean'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}

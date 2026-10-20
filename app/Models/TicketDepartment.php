<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A ticket department (docs/tickets.md §5): WHMCS «Support», «Sales»…, with its own address (and, from PR A2, its own
 * mailbox). Hidden: not offered to customers; clients only: no guest tickets in it.
 */
class TicketDepartment extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['name', 'sort', 'hidden', 'clients_only', 'email'];

    protected $casts = [
        'hidden' => 'boolean',
        'clients_only' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'department_id');
    }

    /**
     * What a customer may choose, in order: not hidden; for a guest, not clients only.
     *
     * @param  Builder<TicketDepartment>  $query
     */
    public function scopeOffered(Builder $query, bool $guest = false): void
    {
        $query->where('hidden', false)->when($guest, fn (Builder $query) => $query->where('clients_only', false))->orderBy('sort')->orderBy('id');
    }
}

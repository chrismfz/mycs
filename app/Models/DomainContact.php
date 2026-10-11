<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registrant/admin/tech/billing contact OWNED by a domain (Πυλώνας A / A1) —
 * first-class, deliberately NOT the ekdosi Customer (pre-fill with explicit
 * confirmation; may diverge). Also the operator's manual-assign aid on
 * unassigned domains. docs/domains/README.md §3.7.
 */
class DomainContact extends Model
{
    use HasFactory;

    public const TYPES = ['registrant', 'admin', 'tech', 'billing'];

    protected $fillable = [
        'domain_id',
        'type',
        'name',
        'org',
        'email',
        'phone',
        'address1',
        'address2',
        'city',
        'postcode',
        'country',
        'registrar_contact_handle',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}

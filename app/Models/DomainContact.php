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
 *
 * @property string $type
 * @property string $name
 * @property string|null $org
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address1
 * @property string|null $address2
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country
 * @property string|null $registrar_contact_handle
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

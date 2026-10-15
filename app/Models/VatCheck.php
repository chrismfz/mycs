<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One check of a contact's EU VAT number (PR G, docs/eu-vat.md): what VIES answered, with its consultation number, or
 * that it gave no answer, or what staff set and why. Kept: the proof that a reverse-charged sale's number was valid then.
 *
 * @property int $id
 * @property int $contact_id
 * @property string $vat_number
 * @property string $result valid, invalid or unavailable
 * @property string $source vies or staff
 * @property int|null $staff_id
 * @property string|null $reason
 * @property string|null $name
 * @property string|null $address
 * @property string|null $request_identifier
 * @property string|null $error
 * @property Carbon $checked_at
 */
class VatCheck extends Model
{
    public const RESULT_VALID = 'valid';

    public const RESULT_INVALID = 'invalid';

    /** VIES gave no answer: the status stays what it was. */
    public const RESULT_UNAVAILABLE = 'unavailable';

    public const SOURCE_VIES = 'vies';

    public const SOURCE_STAFF = 'staff';

    protected $fillable = [
        'contact_id', 'vat_number', 'result', 'source', 'staff_id', 'reason', 'name', 'address', 'request_identifier', 'error',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A category of predefined replies, as WHMCS has them (Invoices, Domains, …). */
class CannedReplyCategory extends Model
{
    protected static function booted(): void
    {
        // Imported from WHMCS and deleted here: a re-run of the import never brings it back.
        static::deleted(function (CannedReplyCategory $record) {
            if ($record->whmcs_id !== null) {
                DB::table('ticket_whmcs_tombstones')->insertOrIgnore(['ref' => 'canned_category:' . $record->whmcs_id, 'created_at' => now()]);
            }
        });
    }

    protected $fillable = ['name', 'sort'];

    /** @return HasMany<CannedReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(CannedReply::class);
    }
}

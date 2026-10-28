<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mailbox of a mailcow domain, as mailcow:sync last read it. */
class MailcowMailbox extends Model
{
    protected $fillable = ['mailcow_domain_id', 'address', 'name', 'active', 'quota_bytes', 'used_bytes', 'messages', 'last_login_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<MailcowDomain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailcowDomain::class, 'mailcow_domain_id');
    }
}

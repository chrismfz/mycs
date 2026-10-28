<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What an account used of its CloudLinux limits at one reading (cpanel:lve): kept two days. */
class CpanelLveSample extends Model
{
    public $timestamps = false;

    protected $fillable = ['cpanel_account_id', 'sampled_at', 'cpu', 'memory_mb', 'entry_processes', 'processes', 'io_kbs', 'iops'];

    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }

    /** @return BelongsTo<CpanelAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CpanelAccount::class, 'cpanel_account_id');
    }
}

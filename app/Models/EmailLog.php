<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int|null $client_id
 */
class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'subject',
        'body',
        'to',
        'sent_at',
        'status',
        'error',
        'job_uuid',
    ];
}

<?php

namespace App\Models;

use App\Support\Redact;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A chat channel staff are told of events in (Mattermost or Slack incoming webhook; sweep of myip, 2026-10-25). The
 * webhook URL is its secret: kept encrypted, never logged, never sent anywhere but itself.
 *
 * @property list<string> $events
 */
class ChatNotificationRule extends Model implements Auditable
{
    use Traits\Auditable;

    /** Who pointed a channel where is audited; never the URL itself (review of #456). */
    protected $auditExclude = ['webhook_url'];

    public const EVENTS = [
        'ticket_opened' => 'New ticket',
        'ticket_reply' => 'Customer reply to a ticket',
        'order_placed' => 'New order',
        'invoice_paid' => 'Invoice paid',
        'payment_matched' => 'Bank payment matched to its invoice',
        'service_suspended' => 'Service suspended',
        'service_unsuspended' => 'Service unsuspended',
        'service_terminated' => 'Service terminated',
        'cancellation_requested' => 'Cancellation requested',
        'vat_rate_differs' => 'EU VAT rate differs from TEDB',
    ];

    protected $fillable = ['name', 'webhook_url', 'events', 'enabled'];

    protected $hidden = ['webhook_url'];

    protected static function booted(): void
    {
        // A URL added or changed: masked in every log from now on.
        static::saved(fn () => Redact::forget());
        static::deleted(fn () => Redact::forget());
    }

    protected function casts(): array
    {
        return ['webhook_url' => 'encrypted', 'events' => 'array', 'enabled' => 'boolean'];
    }
}

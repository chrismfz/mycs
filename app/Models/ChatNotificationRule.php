<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chat channel staff are told of events in (Mattermost or Slack incoming webhook; sweep of myip, 2026-10-25). The
 * webhook URL is its secret: kept encrypted, never logged, never sent anywhere but itself.
 *
 * @property list<string> $events
 */
class ChatNotificationRule extends Model
{
    public const EVENTS = [
        'ticket_opened' => 'New ticket',
        'ticket_reply' => 'Customer reply to a ticket',
        'order_placed' => 'New order',
        'invoice_paid' => 'Invoice paid',
        'service_suspended' => 'Service suspended',
        'service_unsuspended' => 'Service unsuspended',
        'service_terminated' => 'Service terminated',
        'cancellation_requested' => 'Cancellation requested',
    ];

    protected $fillable = ['name', 'webhook_url', 'events', 'enabled'];

    protected $hidden = ['webhook_url'];

    protected function casts(): array
    {
        return ['webhook_url' => 'encrypted', 'events' => 'array', 'enabled' => 'boolean'];
    }
}

<?php

namespace App\Support\Tickets;

use App\Models\Staff;
use App\Models\Ticket;

/**
 * A predefined reply's {{tokens}} filled in for a ticket (docs/tickets.md §6ι). Plain text into the reply's editor,
 * which staff read before sending; an unknown token stays as written, so a typo shows instead of vanishing.
 */
final class CannedReplyExpander
{
    /** WHMCS's merge fields, as the import brings them. */
    public const WHMCS = ['[NAME]' => '{{client.name}}', '[FIRSTNAME]' => '{{client.first_name}}', '[EMAIL]' => '{{client.email}}'];

    /** @return list<string> the tokens offered to staff */
    public static function tokens(): array
    {
        return ['{{client.name}}', '{{client.first_name}}', '{{client.email}}', '{{ticket.reference}}', '{{ticket.subject}}', '{{staff.name}}'];
    }

    public static function expand(string $body, Ticket $ticket, ?Staff $staff = null): string
    {
        $client = $ticket->isGuest() ? null : $ticket->client;
        $name = $client !== null ? (string) $client->name : (string) $ticket->guest_name;
        $values = [
            'client.name' => $name,
            'client.first_name' => $client !== null ? (string) ($client->first_name ?? $name) : (string) strtok($name, ' '),
            'client.email' => $client !== null ? (string) $client->email : (string) $ticket->guest_email,
            'ticket.reference' => (string) $ticket->reference,
            'ticket.subject' => (string) $ticket->subject,
            'staff.name' => (string) ($staff !== null ? $staff->name : ''),
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/i', function (array $match) use ($values) {
            $key = strtolower($match[1]);

            return array_key_exists($key, $values) ? $values[$key] : $match[0];
        }, $body);
    }
}

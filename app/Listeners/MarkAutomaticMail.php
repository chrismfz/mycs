<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * A mail no person wrote says so (RFC 3834 Auto-Submitted), so a vacation responder or a helpdesk never answers it
 * (docs/tickets.md §3 Ε): a Mailable with a public `autoSubmitted` (SystemMail: the staff's notices). Set here, as the
 * mail goes, not by the Mailable's headers(): those become a closure once it is rendered for the log, and a queued
 * job cannot carry one.
 */
class MarkAutomaticMail
{
    public function handle(MessageSending $event): void
    {
        $value = $event->data['autoSubmitted'] ?? null;
        if (in_array($value, ['auto-generated', 'auto-replied'], true)) {
            $headers = $event->message->getHeaders();
            $headers->remove('Auto-Submitted');
            $headers->addTextHeader('Auto-Submitted', $value);
        }
    }
}

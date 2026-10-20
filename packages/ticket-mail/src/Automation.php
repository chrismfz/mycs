<?php

namespace MyCS\TicketMail;

/**
 * Mail no person wrote (docs/tickets.md §3 Ε): auto-replies, bounces, lists and bulk, no-reply senders. It never opens or
 * reopens a ticket and is never answered (a loop between two auto-responders, a bounce storm); it is kept for staff.
 */
final class Automation
{
    private const SENDERS = ['mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'donotreply', 'do-not-reply', 'bounce', 'bounces'];

    /** Why a mail is automated (a reason code), or null when a person wrote it. */
    public static function reason(InboundEmail $email): ?string
    {
        $auto = strtolower(trim((string) $email->header('auto-submitted')));
        if ($auto !== '' && !str_starts_with($auto, 'no')) {
            return 'auto_submitted';
        }
        if (preg_match('/^\s*(bulk|junk|list|auto_reply)\b/i', (string) $email->header('precedence')) === 1) {
            return 'precedence';
        }
        if ($email->header('x-autoreply') !== null || $email->header('x-autorespond') !== null) {
            return 'auto_reply';
        }
        if ($email->header('list-id') !== null || $email->header('list-unsubscribe') !== null) {
            return 'list';
        }
        if (preg_match('/^\s*<?\s*>?\s*$/', (string) ($email->header('return-path') ?? 'x')) === 1) {
            return 'null_sender';
        }
        if (preg_match('#^\s*multipart/report\b#i', (string) $email->header('content-type')) === 1) {
            return 'report';
        }
        // Our own outbound mail, come back (a forwarder, a loop through another helpdesk).
        if ($email->header('x-mycs-ticket') !== null) {
            return 'loop';
        }
        $local = strtolower(strstr($email->fromEmail, '@', true) ?: '');
        foreach (self::SENDERS as $sender) {
            if ($local === $sender || str_starts_with($local, $sender . '+') || str_starts_with($local, $sender . '-')) {
                return 'system_sender';
            }
        }

        return null;
    }
}

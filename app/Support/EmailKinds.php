<?php

namespace App\Support;

use App\Models\BulkMail;
use App\Models\Client;

/**
 * Which of the account's mails its primary contact takes (Contact::EMAIL_PREFERENCES, WHMCS's
 * tblclients.email_preferences): each template is one kind, as WHMCS's email types. The owner gets an account mail
 * only when the primary contact takes its kind (owner 2026-10-30); none chosen (null) is every kind.
 *
 * Never skipped: a mail to a login and not to the account (password reset, email verification, new login, invitation),
 * one staff send by hand (NotificationHelper's $byStaff), and the ones that say a customer is losing a service or a
 * domain (ALWAYS, owner 2026-10-30).
 */
final class EmailKinds
{
    /** Each account template's kind; a template not listed is general. */
    public const KINDS = [
        'new_invoice_created' => 'invoice',
        'invoice_modified' => 'invoice',
        'invoice_paid' => 'invoice',
        'invoice_payment_reminder' => 'invoice',
        'invoice_overdue' => 'invoice',
        'invoice_payment_failed' => 'invoice',
        'invoice_refunded' => 'invoice',
        'credit_applied' => 'invoice',
        'card_payment_due' => 'invoice',
        'card_expiring' => 'invoice',
        'new_order_created' => 'general',
        'new_server_created' => 'product',
        'server_suspended' => 'product',
        'server_unsuspended' => 'product',
        'server_terminated' => 'product',
        'service_cancelled' => 'product',
        'service_cancellation_received' => 'product',
        'ssl_certificate_issued' => 'product',
        'new_ticket_message' => 'support',
        'domain_renewal_notice' => 'domain',
        'domain_free_renewal_notice' => 'domain',
        'domain_expired_notice' => 'domain',
        'domain_registered' => 'domain',
        'domain_transfer_started' => 'domain',
        'domain_transferred' => 'domain',
        'domain_transfer_failed' => 'domain',
        'domain_renewed' => 'domain',
    ];

    /** Sent whatever the contact takes (owner 2026-10-30): a suspension or termination, an overdue or expiry notice. */
    public const ALWAYS = ['server_suspended', 'server_terminated', 'invoice_overdue', 'domain_expired_notice'];

    public static function of(string $templateKey): string
    {
        return self::KINDS[$templateKey] ?? 'general';
    }

    /** The account's owner gets this template's mail: its primary contact takes the kind, or it is one of ALWAYS. */
    public static function sends(Client $client, string $templateKey): bool
    {
        return in_array($templateKey, self::ALWAYS, true) || self::takes($client, self::of($templateKey));
    }

    /** Its primary contact takes $kind (an account without one: every kind, as one never chosen). */
    public static function takes(Client $client, string $kind): bool
    {
        return $client->primaryContact?->takesEmail($kind) ?? true;
    }

    /**
     * A mass mail's kind (owner 2026-10-30): an announcement is about what it targets (services: product, domains:
     * domain, all: general). News has its own «stop these emails» instead: null, not gated here.
     */
    public static function ofBulkMail(BulkMail $mail): ?string
    {
        if ($mail->kind === BulkMail::KIND_NEWS) {
            return null;
        }

        return match ($mail->filters['target'] ?? 'all') {
            'services' => 'product',
            'domains' => 'domain',
            default => 'general',
        };
    }
}

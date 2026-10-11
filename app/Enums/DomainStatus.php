<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a domain (Πυλώνας A) — docs/domains/README.md §3.4/§6.
 *
 * A1 sets these by hand / import; from A2 on, `domains:sync` drives the
 * transitions off the REGISTRAR truth (expires_at + syncDomain/syncTransfer):
 *
 *   pending_register ─▶ active ─▶ expired(grace) ─▶ redemption ─▶ deleted
 *   pending_transfer ─▶ active | (failed → back to previous)
 *   active ─▶ transferred_away   (we lost it to another registrar — record kept,
 *                                 billing/sync stop)
 *   cancelled = local business intent (operator), like invoices.local_status.
 *
 * Greek labels (operator-facing); stored token is the English value.
 */
enum DomainStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case PendingRegister = 'pending_register';
    case PendingTransfer = 'pending_transfer';
    case Expired = 'expired';
    case Grace = 'grace';
    case Redemption = 'redemption';
    case TransferredAway = 'transferred_away';
    case Cancelled = 'cancelled';
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ενεργό',
            self::PendingRegister => 'Εκκρεμεί καταχώρηση',
            self::PendingTransfer => 'Εκκρεμεί μεταφορά',
            self::Expired => 'Ληγμένο',
            self::Grace => 'Grace period',
            self::Redemption => 'Redemption',
            self::TransferredAway => 'Μεταφέρθηκε αλλού',
            self::Cancelled => 'Ακυρωμένο',
            self::Deleted => 'Διαγραμμένο',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingRegister, self::PendingTransfer => 'info',
            self::Expired, self::Grace => 'warning',
            self::Redemption => 'danger',
            self::TransferredAway, self::Cancelled, self::Deleted => 'gray',
        };
    }

    /** The states a renewal should still be offered/staged for. */
    public function isRenewable(): bool
    {
        return match ($this) {
            self::Active, self::Expired, self::Grace => true,
            default => false,
        };
    }

    /**
     * Terminal = the tenant no longer holds (or wants) the name — assignment/
     * billing must never start here. One source for the guard, the assign
     * visibility AND the «Χωρίς πελάτη» worklist badges (kept in sync).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::TransferredAway, self::Cancelled, self::Deleted => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return [self::TransferredAway->value, self::Cancelled->value, self::Deleted->value];
    }

    /**
     * OPERATOR-intent terminals only — the sync must neither run for nor
     * overwrite these. Deleted is deliberately NOT here: the registrar sets it
     * (DEL) and can reverse it (redemption restore), so sync keeps watching.
     */
    public function blocksSync(): bool
    {
        return match ($this) {
            self::TransferredAway, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * The operator-facing «frozen row skipped» line, single-sourced for every
     * import path (two copy-pasted literals had already started to exist).
     */
    public function frozenSkipMessage(string $fqdn): string
    {
        return "Παράλειψη {$fqdn}: κατάσταση «{$this->getLabel()}» — παγωμένο (ιστορικό record).";
    }
}

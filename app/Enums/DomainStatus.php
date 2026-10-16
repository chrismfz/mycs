<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A domain's life (docs/domains.md §3, D-8, D-9, D-18), from Ekdosi. The registrar moves it forward (sync); staff end
 * it. An ended domain is kept with its history, never deleted.
 *
 *   pending_register ─▶ active ─▶ expired ─▶ grace ─▶ redemption ─▶ deleted
 *   pending_transfer ─▶ active
 *   active ─▶ transferred_away (moved to another registrar: billing and sync stop)
 *   cancelled: staff's decision, as invoices'.
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
        return __('domains.status.' . $this->value);
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

    /** It may still be renewed (in redemption it is restored instead, D-13). */
    public function isRenewable(): bool
    {
        return in_array($this, [self::Active, self::Expired, self::Grace], true);
    }

    /** No longer ours: nothing is billed or started for it. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::TransferredAway, self::Cancelled, self::Deleted], true);
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return [self::TransferredAway->value, self::Cancelled->value, self::Deleted->value];
    }

    /**
     * Staff's ends: the sync neither runs for nor overwrites them. Deleted is not one: the registrar sets it and a
     * restore can take it back.
     */
    public function blocksSync(): bool
    {
        return in_array($this, [self::TransferredAway, self::Cancelled], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->getLabel()])->all();
    }
}

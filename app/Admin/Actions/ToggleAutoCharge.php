<?php

namespace App\Admin\Actions;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * WHMCS's «Do not apply auto-charge» on an invoice (PR H2b): staff keep an open invoice from the cron's charge of the
 * customer's saved method (a dispute, a payment agreed another way), or give it back. The customer can still pay it.
 */
class ToggleAutoCharge extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'toggleAutoCharge';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Invoice $record): string => $record->no_auto_charge ? 'Allow auto-charge' : 'Do not auto-charge')
            ->icon(fn (Invoice $record): string => $record->no_auto_charge ? 'heroicon-o-credit-card' : 'heroicon-o-no-symbol')
            ->color('gray')
            // The permission, not the policy's update: that is drafts only with immutable invoices.
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->visible(fn (Invoice $record): bool => $record->status === Invoice::STATUS_UNPAID || $record->no_auto_charge)
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record): string => $record->no_auto_charge
                ? 'The cron charges it to the customer\'s saved payment method again, on its due date and on the retry days.'
                : 'The cron no longer charges it to the customer\'s saved payment method. The customer can still pay it.')
            ->action(function (Invoice $record) {
                // Not through save(): an immutable invoice keeps its other fields; this flag is no part of what it bills.
                Invoice::query()->whereKey($record->id)->update(['no_auto_charge' => !$record->no_auto_charge]);
                $record->refresh();
                Notification::make()->success()->title($record->no_auto_charge ? 'It will not be charged automatically' : 'It will be charged automatically')->send();
            });
    }
}

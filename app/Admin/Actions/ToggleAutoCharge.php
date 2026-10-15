<?php

namespace App\Admin\Actions;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\Events\AuditCustom;

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
                // What the page showed is changed, only if it is still so: two staff at once do not undo each other (code
                // review CR-4). Not through save(): an immutable invoice keeps its other fields.
                $was = (bool) $record->no_auto_charge;
                $changed = Invoice::query()->whereKey($record->id)->where('no_auto_charge', $was)->update(['no_auto_charge' => !$was]);
                $record->refresh();
                if ($changed === 0) {
                    Notification::make()->warning()->title('Someone else changed it: it is shown as it is now')->send();

                    return;
                }
                // Who stopped (or allowed) the collection of money, and when: in the invoice's audit (CR-4).
                $record->auditEvent = $record->no_auto_charge ? 'auto_charge_disabled' : 'auto_charge_enabled';
                $record->isCustomEvent = true;
                $record->auditCustomOld = ['no_auto_charge' => $was];
                $record->auditCustomNew = ['no_auto_charge' => !$was, 'staff_id' => Auth::guard('staff')->id()];
                event(new AuditCustom($record));
                Notification::make()->success()->title($record->no_auto_charge ? 'It will not be charged automatically' : 'It will be charged automatically')->send();
            });
    }
}

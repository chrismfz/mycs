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
 * Two actions, each setting one value: one staff confirming after another changed it never undoes the other (code review
 * CR-4, adversarial ADV-5).
 */
class SetAutoCharge extends Action
{
    private bool $kept = true;

    /** «Do not auto-charge». */
    public static function disable(): static
    {
        return static::make('disableAutoCharge')->keep(true);
    }

    /** «Allow auto-charge». */
    public static function enable(): static
    {
        return static::make('enableAutoCharge')->keep(false);
    }

    public function keep(bool $kept): static
    {
        $this->kept = $kept;
        $this->label($kept ? 'Do not auto-charge' : 'Allow auto-charge')
            ->icon($kept ? 'heroicon-o-no-symbol' : 'heroicon-o-credit-card')
            ->visible(fn (Invoice $record): bool => $kept
                ? $record->status === Invoice::STATUS_UNPAID && !$record->no_auto_charge
                : (bool) $record->no_auto_charge)
            ->modalDescription($kept
                ? 'The cron no longer charges it to the customer\'s saved payment method. The customer can still pay it.'
                : 'The cron charges it to the customer\'s saved payment method again, on its due date and on the retry days.');

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->color('gray')
            // The permission, not the policy's update: that is drafts only with immutable invoices.
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->action(function (Invoice $record) {
                $kept = $this->kept;
                // Not through save(): an immutable invoice keeps its other fields; this flag is no part of what it bills.
                $changed = Invoice::query()->whereKey($record->id)->where('no_auto_charge', !$kept)->update(['no_auto_charge' => $kept]);
                $record->refresh();
                if ($changed === 0) {
                    Notification::make()->info()->title('It was already so')->send();

                    return;
                }
                // Who stopped (or allowed) the collection of money, and when: in the invoice's audit (CR-4).
                $record->auditEvent = $kept ? 'auto_charge_disabled' : 'auto_charge_enabled';
                $record->isCustomEvent = true;
                $record->auditCustomOld = ['no_auto_charge' => !$kept];
                $record->auditCustomNew = ['no_auto_charge' => $kept, 'staff_id' => Auth::guard('staff')->id()];
                event(new AuditCustom($record));
                Notification::make()->success()->title($kept ? 'It will not be charged automatically' : 'It will be charged automatically')->send();
            });
    }
}

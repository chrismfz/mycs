<?php

namespace App\Admin\Actions;

use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\SavedMethodCharge;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Staff settle a charge of a saved method whose outcome is not known (predeploy S1): a crash or a timeout left it unknown,
 * or the gateway took it and its payment was never recorded. Such a charge holds every invoice it could pay (its own, or
 * its Mass Pay group's: shown on each of their pages) for every way of paying until it is settled. Taken: staff record
 * the payment (Add payment, with the gateway's transaction id), which releases it; or, taken and recorded another way
 * (a transfer, Mark Paid, a payment spread by hand), say so here. Not taken (checked at the gateway): mark it here. Under
 * the payment locks, audited. Never one still on its way (SavedMethodCharge::resolvable).
 */
class ResolveCharge extends Action
{
    public const NOT_TAKEN = 'not_taken';

    public const RECORDED = 'recorded';

    public static function getDefaultName(): ?string
    {
        return 'resolveCharge';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve charge')
            ->icon('heroicon-o-question-mark-circle')
            ->color('warning')
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->visible(fn (Invoice $record): bool => self::attempts($record)->isNotEmpty())
            ->modalHeading('A charge whose outcome is not settled')
            ->modalDescription('Check it at the gateway first. If the money was taken, record its payment (Add payment, with the gateway\'s transaction id) on the invoice it was charged to: that settles it. Mark it here only if it was NOT taken, or if its payment was recorded another way.')
            ->schema(fn (Invoice $record) => [
                Select::make('attempt')->label('Charge')->required()
                    ->options(self::attempts($record)->mapWithKeys(fn (ChargeAttempt $attempt) => [
                        $attempt->id => sprintf('Invoice %s · %s · %s · %s %s · %s', $attempt->invoice?->number ?: '#' . $attempt->invoice_id,
                            $attempt->sent_at->format('Y-m-d H:i'), $attempt->billingAgreement->name ?? 'removed method',
                            $attempt->amount, $attempt->currency_code, $attempt->status),
                    ])->all()),
                Radio::make('outcome')->label('At the gateway')->required()->options([
                    self::NOT_TAKEN => 'It was not taken: the invoice can be charged or paid again',
                    self::RECORDED => 'It was taken, and its payment is recorded another way',
                ]),
            ])
            ->modalSubmitActionLabel('Settle it')
            ->action(function (Invoice $record, array $data) {
                $staff = Auth::guard('staff')->user();
                $taken = ($data['outcome'] ?? null) === self::RECORDED;
                $resolved = DB::transaction(function () use ($record, $data, $staff, $taken) {
                    $payments = new RecordPaymentService;
                    $payments->lockInvoice($record->id);
                    $attempt = self::attempts($record)->firstWhere('id', (int) ($data['attempt'] ?? 0));
                    if ($attempt === null) {
                        return null; // settled meanwhile (its payment recorded, or another staff member), or on its way again
                    }
                    if ((int) $attempt->invoice_id !== (int) $record->id) {
                        $payments->lockInvoice((int) $attempt->invoice_id); // a group's charge: the invoice it went to (charge()'s lock)
                        $attempt = self::attempts($record)->firstWhere('id', $attempt->id);
                        if ($attempt === null) {
                            return null;
                        }
                    }
                    $attempt->update(['status' => $taken ? ChargeAttempt::STATUS_SETTLED : ChargeAttempt::STATUS_FAILED, 'finished_at' => now(),
                        'error' => mb_substr(($taken ? 'Taken, its payment recorded another way' : 'Not taken') . ': checked at the gateway by staff #' . ($staff?->getKey() ?? '?'), 0, 250)]);

                    return $attempt;
                });
                if ($resolved === null) {
                    Notification::make()->info()->title('It was already settled, or is on its way again')->send();

                    return;
                }
                // Who released the invoice, and from which charge: in the invoice's audit.
                $record->auditEvent = $taken ? 'charge_resolved_recorded' : 'charge_resolved_not_taken';
                $record->isCustomEvent = true;
                $record->auditCustomOld = ['charge_attempt' => $resolved->id, 'invoice_id' => $resolved->invoice_id, 'status' => 'unresolved'];
                $record->auditCustomNew = ['charge_attempt' => $resolved->id, 'invoice_id' => $resolved->invoice_id, 'status' => $resolved->status, 'staff_id' => $staff?->getKey()];
                event(new AuditCustom($record));
                Notification::make()->success()->title($taken ? 'Settled as taken and recorded' : 'Marked as not taken: the invoice can be paid again')->send();
            });
    }

    /** @return Collection<int, ChargeAttempt> the unsettled charges holding it that staff may settle now */
    private static function attempts(Invoice $record): Collection
    {
        $attempts = SavedMethodCharge::holding($record)->filter(fn (ChargeAttempt $attempt) => SavedMethodCharge::resolvable($attempt))->values();
        $attempts->load('invoice');

        return $attempts;
    }
}

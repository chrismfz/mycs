<?php

namespace App\Admin\Actions;

use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\SavedMethodCharge;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Staff settle a charge of a saved method whose outcome is not known (predeploy S1): a crash or a timeout left it unknown,
 * or the gateway took it and its payment was never recorded. Such a charge holds the invoice for every way of paying until
 * it is settled. Taken: staff record the payment (Add payment, with the gateway's transaction id), which releases it.
 * Not taken (checked at the gateway): this action, under the payment locks, audited, releases it.
 */
class ResolveCharge extends Action
{
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
            ->visible(fn (Invoice $record): bool => SavedMethodCharge::unresolved([(int) $record->id])->isNotEmpty())
            ->modalHeading('A charge whose outcome is not known')
            ->modalDescription('Check it at the gateway first. If the money was taken, record the payment instead (Add payment, with the gateway\'s transaction id). Only if it was NOT taken, mark it here: the invoice can then be charged or paid again.')
            ->schema(fn (Invoice $record) => [
                Select::make('attempt')->label('Charge')->required()
                    ->options(SavedMethodCharge::unresolved([(int) $record->id])->mapWithKeys(fn (ChargeAttempt $attempt) => [
                        $attempt->id => sprintf('%s · %s · %s %s · %s', $attempt->sent_at->format('Y-m-d H:i'), $attempt->billingAgreement->name ?? 'removed method',
                            $attempt->amount, $attempt->currency_code, $attempt->status),
                    ])->all()),
            ])
            ->modalSubmitActionLabel('It was not taken')
            ->action(function (Invoice $record, array $data) {
                $staff = Auth::guard('staff')->user();
                $resolved = DB::transaction(function () use ($record, $data, $staff) {
                    (new RecordPaymentService)->lockInvoice($record->id);
                    $attempt = SavedMethodCharge::unresolved([(int) $record->id])->firstWhere('id', (int) ($data['attempt'] ?? 0));
                    if ($attempt === null) {
                        return null; // settled meanwhile (its payment recorded, or another staff member)
                    }
                    $attempt->update(['status' => ChargeAttempt::STATUS_FAILED, 'finished_at' => now(),
                        'error' => mb_substr('Not taken: checked at the gateway by staff #' . ($staff?->getKey() ?? '?'), 0, 250)]);

                    return $attempt;
                });
                if ($resolved === null) {
                    Notification::make()->info()->title('It was already settled')->send();

                    return;
                }
                // Who released the invoice, and from which charge: in the invoice's audit.
                $record->auditEvent = 'charge_resolved_not_taken';
                $record->isCustomEvent = true;
                $record->auditCustomOld = ['charge_attempt' => $resolved->id, 'status' => 'unresolved'];
                $record->auditCustomNew = ['charge_attempt' => $resolved->id, 'status' => ChargeAttempt::STATUS_FAILED, 'staff_id' => $staff?->getKey()];
                event(new AuditCustom($record));
                Notification::make()->success()->title('Marked as not taken: the invoice can be paid again')->send();
            });
    }
}

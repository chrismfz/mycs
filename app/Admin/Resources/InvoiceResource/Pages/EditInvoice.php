<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Actions\CloseInvoiceAsPaid;
use App\Admin\Actions\MarkInvoicePaid;
use App\Admin\Actions\SendInvoiceEmail;
use App\Admin\Resources\InvoiceResource;
use App\Classes\PDF;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    /** The lines are saved before the invoice: a refusal of the money rules undoes them too (MT-1c). */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * The model's money rules (InvoiceObserver, InvoiceItemObserver: paid only without a balance, a paid invoice keeps
     * its lines) refuse with a message, not an error page; the whole save is rolled back.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Invoice not saved'))->body($e->getMessage())->send();
        }
    }

    /** The status the form was filled with (or last saved): to tell a change staff made from one made meanwhile. */
    public ?string $statusWhenLoaded = null;

    protected function afterFill(): void
    {
        $this->statusWhenLoaded = $this->data['status'] ?? null;
    }

    /**
     * Its status changed since the form was filled (closed as paid after the last Save, a payment, the cron): a form
     * that did not touch it writes the current one, never the old one back (code review of 2b). One that did, and
     * disagrees, is refused: staff look again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = Invoice::query()->whereKey($this->record->getKey())->value('status');
        if ($current !== null && $current !== $this->statusWhenLoaded && array_key_exists('status', $data) && $data['status'] !== $current) {
            if ($data['status'] !== $this->statusWhenLoaded) {
                $this->record->refresh();
                $this->refreshFormData(['status']);
                $this->statusWhenLoaded = $current;
                Notification::make()->warning()->title(__('Invoice not saved'))
                    ->body(__('Its status changed meanwhile (now :status): check it and save again.', ['status' => $current]))->send();

                throw new Halt;
            }
            $data['status'] = $current;
            $this->data['status'] = $current;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->statusWhenLoaded = $this->data['status'] ?? null;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (config('settings.immutable_invoices_enabled', false) && $this->record->status !== Invoice::STATUS_DRAFT) {
            redirect(InvoiceResource::getUrl('view', ['record' => $record]));
        }
    }

    /** An invoice with payments keeps its customer and currency (docs/money-trail.md §8): say why, not an error page. */
    protected function beforeSave(): void
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;
        $moves = (array_key_exists('client_id', $this->data) && (int) $this->data['client_id'] !== (int) $invoice->client_id)
            || (array_key_exists('currency_code', $this->data) && $this->data['currency_code'] !== $invoice->currency_code);
        if ($moves && $invoice->hasPayments()) {
            Notification::make()->danger()->title(__('Invoice not saved'))
                ->body(__('An invoice with payments keeps its customer and currency: delete its payments first (a refunded payment keeps the invoice where it is).'))->send();

            throw new Halt;
        }
        // Paid only by recording its payment (MT-1c; InvoiceObserver::updating refuses it too): say why.
        if (($this->data['status'] ?? null) === Invoice::STATUS_PAID && $invoice->getOriginal('status') !== Invoice::STATUS_PAID
            && RecordPaymentService::toCents($invoice->fresh()->remaining) > 0) {
            Notification::make()->danger()->title(__('Invoice not saved'))
                ->body(__('An invoice becomes paid when its payment is recorded: use Mark Paid instead.'))->send();

            throw new Halt;
        }
        // Paid over its total (a line taken off, a discount): the part over is the customer's, given by «Close as paid».
        if (($this->data['status'] ?? null) === Invoice::STATUS_PAID && $invoice->getOriginal('status') !== Invoice::STATUS_PAID
            && RecordPaymentService::toCents($invoice->fresh()->remaining) < 0) {
            Notification::make()->danger()->title(__('Invoice not saved'))
                ->body(__('More was paid than it bills: close it with «Close as paid», which gives the part over as account credit.'))->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            MarkInvoicePaid::make(),
            CloseInvoiceAsPaid::make(),
            Action::make('publish')
                ->label('Publish')
                ->action(function (Invoice $record) {
                    $this->changeInvoiceStatusToPending($record);
                })
                ->visible(fn (Invoice $record): bool => $record->status === Invoice::STATUS_DRAFT)
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->successRedirectUrl(InvoiceResource::getUrl('index')),
            DeleteAction::make()
                ->before(function (Invoice $record, DeleteAction $action): void {
                    if ($record->hasPayments()) {
                        Notification::make()->danger()->title(__('Invoice not deleted'))
                            ->body(__('An invoice with payments cannot be deleted: cancel it, or delete its transactions first.'))->send();
                        $action->cancel();
                    }
                    if ($record->recordsForgonePeriod()) { // Δ3a: deleted, its period would be billed again
                        Notification::make()->danger()->title(__('Invoice not deleted'))
                            ->body(__('This cancelled invoice records a service period given up on: it is kept (reopen it to bill that period again).'))->send();
                        $action->cancel();
                    }
                }),
            SendInvoiceEmail::make(),
            Action::make('pdf')
                ->label('Download PDF')
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->stream();
                    }, 'invoice-' . ($invoice->number ?? $invoice->id) . '.pdf');
                }),
            AuditAction::make()
                ->auditChildren([
                    'items',
                    'transactions',
                ]),
        ];
    }

    protected function changeInvoiceStatusToPending(Invoice $invoice): void
    {
        $invoice->status = Invoice::STATUS_UNPAID;
        $invoice->save();
    }
}

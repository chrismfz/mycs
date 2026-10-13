<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Actions\MarkInvoicePaid;
use App\Admin\Resources\InvoiceResource;
use App\Classes\PDF;
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
        $moves = (array_key_exists('user_id', $this->data) && (int) $this->data['user_id'] !== (int) $invoice->user_id)
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
    }

    protected function getHeaderActions(): array
    {
        return [
            MarkInvoicePaid::make(),
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
                }),
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
        $invoice->status = Invoice::STATUS_PENDING;
        $invoice->save();
    }
}

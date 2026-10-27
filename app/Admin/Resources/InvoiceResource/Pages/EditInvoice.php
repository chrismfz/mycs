<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\ApplyCreditAction;
use App\Admin\Actions\AuditAction;
use App\Admin\Actions\CloseInvoiceAsPaid;
use App\Admin\Actions\MarkInvoicePaid;
use App\Admin\Actions\SendInvoiceEmail;
use App\Admin\Actions\SendToEkdosi;
use App\Admin\Actions\SetAutoCharge;
use App\Admin\Actions\SplitInvoiceAction;
use App\Admin\Concerns\RemembersRecent;
use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Classes\PDF;
use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

class EditInvoice extends EditRecord
{
    use RemembersRecent;
    use TwoColumnForm;

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
            // Under the payment locks (customer, then invoice), taken before the lines are written: a payment landing
            // now waits for this save, and an invoice with payments keeps its amounts (StaffLineEdit, sweep Π2.11).
            // Filament's own transaction is a savepoint inside this one: «Saved» is sent just before the outer commit.
            StaffLineEdit::run($this->record->getKey(), false, function (Invoice $invoice) use ($shouldRedirect, $shouldSendSavedNotification) {
                if ($this->linesChangeAmounts()) {
                    // Also when this save reopens it (cancelled → unpaid): the status it will have, not only the stored one.
                    StaffLineEdit::refuseWithPayments($invoice, $this->data['status'] ?? null);
                    // A document issued while the form was open: the form now locks the amounts and would drop the change
                    // without a word (the observer never sees it); refused, as before the lock (adversarial ADV-1 of #483).
                    if (EkdosiDocument::activeOn($invoice->getKey(), true)) {
                        throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'));
                    }
                }
                parent::save($shouldRedirect, $shouldSendSavedNotification);
            });
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Invoice not saved'))->body($e->getMessage())->send();
        }
    }

    /** The form adds, takes off or changes the amount of a line (price, quantity, taxed): not only its description. */
    private function linesChangeAmounts(): bool
    {
        // Read locked (adversarial A4): an app change committed while this save waited for the invoice lock is seen.
        $stored = InvoiceItem::query()->where('invoice_id', $this->record->getKey())->lockForUpdate()->get()
            ->mapWithKeys(fn (InvoiceItem $line) => ['record-' . $line->getKey() => self::amounts($line->price, $line->quantity, $line->taxed)]);
        $form = collect($this->data['items'] ?? [])
            ->map(fn ($line) => self::amounts($line['price'] ?? null, $line['quantity'] ?? null, $line['taxed'] ?? null));

        return $stored->keys()->sort()->values()->all() !== $form->keys()->sort()->values()->all()
            || $form->contains(fn (array $amounts, string $key) => $stored->get($key) !== $amounts);
    }

    /**
     * Anything in the form not saved: its lines, or its own details (client, currency, status, dates). «Split» moves the
     * saved lines and reloads the page, which would drop what was typed (ADV-1 of #414).
     */
    public function formUnsaved(): bool
    {
        $invoice = Invoice::query()->findOrFail($this->record->getKey());
        $date = fn ($value): ?string => blank($value) ? null : Carbon::parse($value)->toDateString();
        $details = fn (array $fields): array => [(string) ($fields['client_id'] ?? ''), (string) ($fields['currency_code'] ?? ''),
            (string) ($fields['status'] ?? ''), $date($fields['due_at'] ?? null), $date($fields['created_at'] ?? null)];

        return $this->linesUnsaved() || $details($this->data ?? []) !== $details([
            'client_id' => $invoice->client_id, 'currency_code' => $invoice->currency_code, 'status' => $invoice->status,
            'due_at' => $invoice->due_at, 'created_at' => $invoice->created_at,
        ]);
    }

    /** The lines in the form differ from those saved (added, taken off, or any field changed): «Split» moves the saved ones. */
    public function linesUnsaved(): bool
    {
        $stored = InvoiceItem::query()->where('invoice_id', $this->record->getKey())->get()
            ->mapWithKeys(fn (InvoiceItem $line) => ['record-' . $line->getKey() => [...self::amounts($line->price, $line->quantity, $line->taxed), (string) $line->description]]);
        $form = collect($this->data['items'] ?? [])
            ->map(fn ($line) => [...self::amounts($line['price'] ?? null, $line['quantity'] ?? null, $line['taxed'] ?? null), (string) ($line['description'] ?? '')]);

        return $stored->keys()->sort()->values()->all() !== $form->keys()->sort()->values()->all()
            || $form->contains(fn (array $line, string $key) => $stored->get($key) !== $line);
    }

    /** @return array{0: int, 1: string, 2: bool} */
    private static function amounts(mixed $price, mixed $quantity, mixed $taxed): array
    {
        // A quantity as the line stores and bills it, a whole number; anything else is a change (adversarial A1).
        $units = is_numeric($quantity) && (float) $quantity == (int) $quantity ? (string) (int) $quantity : 'x' . $quantity;

        return [RecordPaymentService::toCents(is_numeric($price) ? $price : 0), $units, (bool) $taxed];
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

        // Its issue date (INV-17): set here, never by fill(); only while it is a draft or unpaid.
        $issued = self::issuedAt($data['created_at'] ?? null);
        $invoice = $this->record;
        // Open by its status read now ($current, above), not as the page loaded it: one paid meanwhile keeps its
        // date (code review 2).
        if ($issued !== null && $invoice instanceof Invoice && in_array($current ?? $invoice->getOriginal('status'), [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID], true)
            && $issued->toDateString() !== $invoice->created_at?->toDateString()) {
            $invoice->created_at = $issued;
        }
        unset($data['created_at']);

        return $data;
    }

    /** A date from the form, as the moment to keep: today's is now, an earlier day's its start; a later one is none. */
    public static function issuedAt(mixed $date): ?Carbon
    {
        if (!filled($date)) {
            return null;
        }
        $day = Carbon::parse((string) $date)->startOfDay();
        if ($day->isFuture()) {
            return null;
        }

        return $day->isToday() ? now() : $day;
    }

    /**
     * A payment recorded, refunded or deleted in the tables below (review of #483): the page reads the invoice again, its
     * status and whether its lines keep their amounts, as after Mark Paid.
     */
    #[On(TransactionsRelationManager::PAYMENTS_CHANGED)]
    public function paymentsChanged(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['status']);
        $this->statusWhenLoaded = $this->data['status'] ?? null;
    }

    protected function afterSave(): void
    {
        $this->statusWhenLoaded = $this->data['status'] ?? null;
        // A request to Ekdosi that the save took off (its lines, its status: EkdosiRequest::forget writes the table, not
        // this model): the header offers «Send to Ekdosi» again, not «Withdraw» (live test of 2026-10-27).
        $this->record->forceFill(Invoice::query()->whereKey($this->record->getKey())
            ->first(['ekdosi_requested_at', 'ekdosi_requested_by'])?->getAttributes() ?? [])->syncOriginal();
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
            ApplyCreditAction::make(),
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
                    // Ekdosi issued a fiscal document from it (E2): kept, as the observer says — told here, not a 500.
                    if (EkdosiDocument::query()->where('invoice_id', $record->id)->exists()) {
                        Notification::make()->danger()->title(__('Invoice not deleted'))
                            ->body(__('This invoice has a fiscal document from Ekdosi: it is kept. Cancel or refund it instead.'))->send();
                        $action->cancel();
                    }
                    if ($record->recordsForgonePeriod()) { // Δ3a: deleted, its period would be billed again
                        Notification::make()->danger()->title(__('Invoice not deleted'))
                            ->body(__('This cancelled invoice records a service period given up on: it is kept (reopen it to bill that period again).'))->send();
                        $action->cancel();
                    }
                }),
            SendInvoiceEmail::make(),
            SplitInvoiceAction::make(),
            SetAutoCharge::disable(),
            SetAutoCharge::enable(),
            SendToEkdosi::make(),
            SendToEkdosi::withdraw(),
            Action::make('pdf')
                ->label('Download PDF')
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->output(); // the PDF only: stream() is a whole response, its headers written into the file
                    }, $invoice->pdfFileName());
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

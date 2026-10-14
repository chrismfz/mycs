<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\CloseInvoiceAsPaid;
use App\Admin\Actions\MarkInvoicePaid;
use App\Admin\Actions\SendInvoiceEmail;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\ServiceResource;
use App\Classes\PDF;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (!config('settings.immutable_invoices_enabled', false)) {
            redirect(InvoiceResource::getUrl('edit', ['record' => $record]));
        }
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Details')
                    ->schema([
                        TextEntry::make('number')
                            ->label('Invoice Number'),
                        TextEntry::make('user.name')
                            ->label('User'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => Invoice::statusColor($state))
                            ->formatStateUsing(fn (string $state): string => Invoice::statusLabel($state)),
                        TextEntry::make('currency.code')
                            ->label('Currency'),
                        TextEntry::make('created_at')
                            ->label('Issued At')
                            ->dateTime(),
                        TextEntry::make('due_at')
                            ->label('Due At')
                            ->date(),
                        TextEntry::make('formattedTotal')
                            ->label('Total'),
                        TextEntry::make('formattedCurrentBalance')
                            ->label('Remaining'),
                    ])
                    ->columns(3),
                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('description')
                                    ->label('Description')
                                    // A line of a service deleted since keeps its text, without a link (DATA-2).
                                    ->url(fn ($record) => in_array($record->reference_type, [Service::class, ServiceUpgrade::class]) && $record->reference
                                        ? ServiceResource::getUrl('edit', ['record' => $record->reference_type == Service::class ? $record->reference_id : $record->reference->service_id])
                                        : null)
                                    ->color(fn ($record) => in_array($record->reference_type, [Service::class, ServiceUpgrade::class]) && $record->reference ? 'primary' : null),
                                TextEntry::make('price')
                                    ->label('Price')
                                    ->money(fn ($record) => $record->invoice->currency_code ?? 'USD'),
                                TextEntry::make('quantity')
                                    ->label('Quantity'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            MarkInvoicePaid::make(),
            CloseInvoiceAsPaid::make(),
            SendInvoiceEmail::make(),
            Action::make('cancel')
                // Cancelling writes off what the customer owes: only staff who may change invoices (R1 of #84; this
                // page opens to anyone who may view them).
                ->authorize(fn (Invoice $record): bool => (bool) auth()->user()?->can('update', $record))
                ->label('Cancel Invoice')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    TextInput::make('cancellation_reason')
                        ->label('Cancellation Reason')
                        ->required(),
                ])
                ->action(function (Invoice $invoice, array $data) {
                    try {
                        $invoice->update([
                            'status' => Invoice::STATUS_CANCELLED,
                            'cancellation_reason' => $data['cancellation_reason'],
                        ]);
                    } catch (DisplayException $e) { // it holds payments: refund them first (PR 2c)
                        Notification::make()->danger()->title(__('Invoice not cancelled'))->body($e->getMessage())->send();

                        throw new Halt;
                    }
                })
                // A paid invoice is refunded, not cancelled (INV-16, PR 2c); a refunded one is final.
                ->visible(fn (Invoice $invoice): bool => !in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED], true)),
            Action::make('pdf')
                ->label('Download PDF')
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->stream();
                    }, 'invoice-' . ($invoice->number ?? $invoice->id) . '.pdf');
                }),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            TransactionsRelationManager::class,
            AdjustmentNotesRelationManager::class,
        ];
    }
}

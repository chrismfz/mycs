<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Services\Invoice\MergeInvoices;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;

/**
 * «Merge» on the invoice list (PR E, decision 8): the chosen invoices' lines move onto one of them, by default the oldest.
 * The others stay, cancelled, «merged into #X». Refused with payments, a credit note, another customer or currency.
 */
class MergeInvoicesAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('merge')
            ->label(__('Merge'))
            ->icon('heroicon-o-arrows-pointing-in')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('update', Invoice::class))
            ->deselectRecordsAfterCompletion()
            ->modalDescription(__('The lines of the other invoices move onto the one you keep, with their tax as issued. It takes the earliest due date. The others are cancelled, «merged into» it. Only unpaid invoices of one customer and currency, without payments or credit notes.'))
            ->form(fn (Collection $records): array => [
                Select::make('target')
                    ->label(__('Keep invoice'))
                    ->required()
                    ->options($records->sortBy('id')->mapWithKeys(fn (Invoice $invoice) => [
                        $invoice->id => '#' . ($invoice->number ?: $invoice->id) . ' · ' . $invoice->formattedTotal . ' · ' . $invoice->due_at?->toDateString(),
                    ])->all())
                    ->default($records->min('id')),
            ])
            ->action(function (Collection $records, array $data): void {
                // Each invoice, as staff may change it (adversarial E-ADV-4: immutable invoices are changed only as drafts).
                if ($records->contains(fn (Invoice $invoice) => !auth()->user()?->can('update', $invoice))) {
                    Notification::make()->danger()->title(__('Invoices not merged'))->body(__('You may not change one of these invoices.'))->send();

                    throw new Halt;
                }
                try {
                    $target = (new MergeInvoices)->handle((int) $data['target'], $records->pluck('id')->map(fn ($id) => (int) $id)->all());
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Invoices not merged'))->body(e($e->getMessage()))->send();

                    throw new Halt;
                }
                Notification::make()->success()->title(__('Merged into invoice :invoice', ['invoice' => '#' . ($target->number ?: $target->id)]))->send();
            });
    }
}

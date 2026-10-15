<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoice\SplitInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * «Split» on an invoice (PR E): the chosen lines move to a new invoice with the same customer, currency, due date and
 * billing details. Refused as a merge is (payments, credit notes, a line applied); at least one line stays.
 */
class SplitInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('split')
            ->label(__('Split'))
            ->icon('heroicon-o-scissors')
            ->authorize(fn (Invoice $record): bool => (bool) auth()->user()?->can('update', $record))
            ->visible(fn (Invoice $record): bool => $record->status === Invoice::STATUS_UNPAID && $record->items()->count() > 1)
            ->modalDescription(__('The lines you choose move to a new unpaid invoice, with their tax as issued and the same due date. Its email is not sent.'))
            ->form(fn (Invoice $record): array => [
                CheckboxList::make('lines')
                    ->label(__('Move to a new invoice'))
                    ->required()
                    ->options($record->items()->orderBy('id')->get()->mapWithKeys(fn (InvoiceItem $line) => [
                        $line->id => $line->description . ' · ' . number_format((float) $line->line_total, 2, '.', '') . ' ' . $record->currency_code,
                    ])->all()),
            ])
            ->action(function (Invoice $record, array $data, $livewire): void {
                try {
                    $new = (new SplitInvoice)->handle($record->id, array_map('intval', $data['lines'] ?? []));
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Invoice not split'))->body($e->getMessage())->send();

                    throw new Halt;
                }
                Notification::make()->success()->title(__('Split into invoice :invoice', ['invoice' => '#' . ($new->number ?: $new->id)]))->send();
                $livewire->redirect(InvoiceResource::getUrl('edit', ['record' => $record->id]));
            });
    }
}

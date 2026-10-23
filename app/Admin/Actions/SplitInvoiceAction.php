<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
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
            ->action(fn (Invoice $record, array $data, $livewire) => self::split($record, array_map('intval', $data['lines'] ?? []), $livewire));
    }

    /**
     * «With selected → Split to a new invoice» below the lines (owner 2026-10-24): the lines ticked there, as saved. A
     * line not saved yet, or lines changed and not saved, are refused: the split moves the stored lines, and the page
     * reloads after it, which would drop what was typed.
     */
    public static function selected(): Action
    {
        return Action::make('splitSelected')
            ->label(__('Split to a new invoice'))
            ->icon('heroicon-o-scissors')
            ->color('gray')
            ->size('sm')
            ->authorize(fn (Invoice $record): bool => (bool) auth()->user()?->can('update', $record))
            ->visible(fn (?Invoice $record): bool => $record?->status === Invoice::STATUS_UNPAID && $record->items()->count() > 1)
            ->requiresConfirmation()
            ->modalHeading(__('Split to a new invoice'))
            ->modalDescription(__('The lines you ticked move to a new unpaid invoice, with their tax as issued and the same due date. Its email is not sent.'))
            ->action(function (Invoice $record, EditInvoice $livewire): void {
                $ticked = collect($livewire->data['items'] ?? [])->filter(fn ($line) => (bool) ($line['selected'] ?? false))->keys();
                if ($ticked->isEmpty()) {
                    Notification::make()->warning()->title(__('Invoice not split'))->body(__('Tick the lines to move first.'))->send();

                    return;
                }
                if ($ticked->contains(fn ($key) => !preg_match('/^record-\d+$/', (string) $key)) || $livewire->linesUnsaved()) {
                    Notification::make()->warning()->title(__('Invoice not split'))->body(__('Save the lines first: the split moves them as saved.'))->send();

                    return;
                }
                self::split($record, $ticked->map(fn ($key) => (int) substr((string) $key, strlen('record-')))->values()->all(), $livewire);
            });
    }

    /** @param  list<int>  $lines */
    private static function split(Invoice $record, array $lines, $livewire): void
    {
        try {
            $new = (new SplitInvoice)->handle($record->id, $lines);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Invoice not split'))->body($e->getMessage())->send();

            throw new Halt;
        }
        Notification::make()->success()->title(__('Split into invoice :invoice', ['invoice' => '#' . ($new->number ?: $new->id)]))->send();
        $livewire->redirect(InvoiceResource::getUrl('edit', ['record' => $record->id]));
    }
}

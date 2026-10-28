<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\InvoiceResource;
use App\Models\BankMovement;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * «Payments tracking» (WHMCS's widget, owner 2026-10-24): the credits of the bank accounts from their alert emails,
 * newest first. A credit not yet seen to is «pending»: staff find its invoice (🔍) and tick it once done. The card
 * acquirer's settlements are ignored, what was in the mailbox before the reading started is history. A credit our
 * mail server cannot vouch the bank sent is marked: never trusted for the balances or the matching.
 */
class PaymentsTracking extends TableWidget
{
    // Right of Income, below Support (owner 2026-10-24); the renewals in line below them.
    protected static ?int $sort = 13;

    protected static ?string $pollingInterval = '60s';

    protected static ?string $heading = 'Payments tracking';

    public function table(Table $table): Table
    {
        return $table
            ->query(BankMovement::query()->credits()->with(['acknowledgedBy', 'invoice']))
            ->defaultSort('executed_at', 'desc')
            // A widget pages «simply» (no count): here the page and the total show, with thousands of movements (owner 2026-10-28).
            ->paginated([10, 25, 50, 100])->defaultPaginationPageOption(10)->paginationMode(PaginationMode::Default)
            ->recordClasses(fn (BankMovement $record) => $record->status === BankMovement::PENDING ? 'bg-warning-50 dark:bg-warning-500/10' : null)
            ->columns([
                TextColumn::make('status')->label('')->width('1%')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        BankMovement::PENDING => '☐',
                        BankMovement::ACKNOWLEDGED => '✓',
                        BankMovement::IGNORED => '–',
                        BankMovement::UNVERIFIED => '?',
                        BankMovement::MATCHED => '✓✓',
                        default => '·',
                    })
                    ->tooltip(fn (BankMovement $record) => match ($record->status) {
                        BankMovement::PENDING => 'Pending: for staff' . ($record->match_note ? ' (not matched: ' . $record->match_note . ')' : ''),
                        BankMovement::MATCHED => 'Matched to invoice ' . ($record->invoice->number ?? '#' . $record->invoice_id) . ' and recorded on it ' . $record->matched_at?->diffForHumans(),
                        BankMovement::ACKNOWLEDGED => 'Seen to by ' . ($record->acknowledgedBy->name ?? 'staff') . ' ' . $record->acknowledged_at?->diffForHumans(),
                        BankMovement::IGNORED => 'Ignored (a configured reference: card settlements)',
                        BankMovement::UNVERIFIED => 'Unverified: not vouched for by our mail server, or not one of our accounts. Check it at the bank.',
                        default => 'In the mailbox before the reading started',
                    }),
                TextColumn::make('amount')->label('Amount')->alignEnd()
                    ->formatStateUsing(fn (BankMovement $record) => number_format((float) $record->amount, 2, ',', '.') . ' €')
                    ->description(fn (BankMovement $record) => $record->bankName()),
                TextColumn::make('reason')->label('Reference')->wrap()->searchable()
                    ->description(fn (BankMovement $record) => $record->authentic ? null
                        : (in_array($record->authenticity, ['not_our_account', BankMovement::TAKEN_OUT], true) ? '⚠ Not one of our accounts (' . $record->account . '): check it at the bank'
                            : '⚠ Not vouched for by our mail server: check it at the bank')),
                TextColumn::make('executed_at')->label('When')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([BankMovement::PENDING => 'Pending', BankMovement::ACKNOWLEDGED => 'Seen to',
                    BankMovement::MATCHED => 'Matched', BankMovement::IGNORED => 'Ignored', BankMovement::UNVERIFIED => 'Unverified', BankMovement::HISTORY => 'History']),
                SelectFilter::make('bank')->options(BankMovement::BANKS),
            ])
            ->recordActions([
                Action::make('find')->label('')->icon('heroicon-o-magnifying-glass')->tooltip('Invoices that match')
                    ->modalHeading('Invoices that match the payment')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (BankMovement $record) => new HtmlString(self::matchesHtml($record))),
                Action::make('acknowledge')->label('')->icon('heroicon-o-check')->color('success')->tooltip('Seen to')
                    ->visible(fn (BankMovement $record) => in_array($record->status, [BankMovement::PENDING, BankMovement::UNVERIFIED], true) && self::canUpdate())
                    ->action(function (BankMovement $record) {
                        // Staff's from now on: never matched automatically (adversarial round 2 of #458).
                        $record->update(['status' => BankMovement::ACKNOWLEDGED, 'acknowledged_by' => auth('staff')->id(), 'acknowledged_at' => now(),
                            'match_note' => $record->match_note ?? 'Seen to by staff']);
                        Notification::make()->success()->title('Marked seen to')->send();
                    }),
                Action::make('reopen')->label('')->icon('heroicon-o-arrow-uturn-left')->color('gray')->tooltip('Back to pending')
                    ->visible(fn (BankMovement $record) => in_array($record->status, [BankMovement::ACKNOWLEDGED, BankMovement::IGNORED], true) && self::canUpdate())
                    // Back to what it was: pending only for a vouched-for credit of ours (adversarial round 2 of #450).
                    ->action(fn (BankMovement $record) => $record->update(['status' => $record->authentic ? BankMovement::PENDING : BankMovement::UNVERIFIED,
                        'acknowledged_by' => null, 'acknowledged_at' => null, 'match_note' => $record->match_note ?? 'Reopened by staff'])),
            ]);
    }

    /**
     * The invoices whose number (or id) the reference names, and the unpaid ones whose remaining is the amount.
     *
     * @return array{named: Collection<int, Invoice>, amount: Collection<int, Invoice>, tokens: list<string>}
     */
    public static function matches(BankMovement $movement): array
    {
        // The same rule as the automatic matching (PaymentMatcher): by number, by id only without one.
        $tokens = $movement->invoiceTokens();
        $named = $movement->namedInvoices()->with('client')->where('currency_code', 'EUR')->limit(20)->get();
        // The bank's money is EUR: an invoice in another currency is no match (review of #450). Every unpaid one, read in
        // chunks with what its total needs (no query per invoice).
        $amount = collect();
        Invoice::query()->with(['client', 'items', 'transactions', 'adjustmentNotes'])->where('status', Invoice::STATUS_UNPAID)
            ->where('currency_code', 'EUR')->orderByDesc('id')
            ->chunk(200, function ($invoices) use ($movement, $amount) {
                foreach ($invoices as $invoice) {
                    if (bccomp(number_format((float) $invoice->remaining, 2, '.', ''), (string) $movement->amount, 2) === 0) {
                        $amount->push($invoice);
                    }
                }

                return $amount->count() < 20;
            });

        return ['named' => $named, 'amount' => $amount, 'tokens' => $tokens];
    }

    private static function matchesHtml(BankMovement $movement): string
    {
        $found = self::matches($movement);
        $row = fn (Invoice $invoice) => '<tr class="border-t border-gray-200 dark:border-white/10"><td class="py-1 pe-3"><a class="text-primary-600 underline" target="_blank" href="'
            . e(InvoiceResource::getUrl('edit', ['record' => $invoice])) . '">' . e($invoice->number ?: '#' . $invoice->id) . '</a></td><td class="pe-3">'
            . e($invoice->client->name ?? '') . '</td><td class="pe-3">' . e($invoice->status) . '</td><td class="text-end tabular-nums">'
            . e((string) $invoice->formattedRemaining) . '</td></tr>';
        $section = fn (string $title, Collection $invoices) => '<p class="mt-3 text-sm font-medium">' . e($title) . '</p>'
            . ($invoices->isEmpty() ? '<p class="text-sm text-gray-500">None.</p>'
                : '<table class="w-full text-sm">' . $invoices->map($row)->implode('') . '</table>');

        return '<p class="text-sm">' . e(number_format((float) $movement->amount, 2, ',', '.') . ' € · ' . $movement->bankName() . ' · ' . $movement->reason) . '</p>'
            . $section('Named in the reference' . ($found['tokens'] !== [] ? ' (' . implode(', ', $found['tokens']) . ')' : ''), $found['named'])
            . $section('Unpaid, with this amount left to pay', $found['amount']);
    }

    private static function canUpdate(): bool
    {
        return (bool) auth('staff')->user()?->hasPermission('admin.bank_payments.update');
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.payments_tracking') && BankMovement::query()->exists();
    }
}

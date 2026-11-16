<?php

namespace App\Admin\Resources\ClientResource\Widgets;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\InvoiceTransaction;
use App\Services\Admin\ClientSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The client's money in, as WHMCS's «Transactions» tab (owner 2026-11-16): received, fees, refunded and what is left,
 * per currency. Received is the Revenue chart's rule (succeeded, not paid from credit: a payment spread over several
 * invoices counts once).
 */
class ClientTransactionTotals extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public ?Client $record = null;

    protected int|array|null $columns = 4;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }
        $rows = InvoiceTransaction::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_transactions.invoice_id')
            ->where('invoices.client_id', $this->record->id)
            ->where('invoice_transactions.status', InvoiceTransactionStatus::Succeeded)
            ->where('invoice_transactions.is_credit_transaction', false)
            ->groupBy('invoices.currency_code')
            ->selectRaw('invoices.currency_code as currency, SUM(invoice_transactions.amount) as received, SUM(COALESCE(invoice_transactions.fee, 0)) as fees, SUM(invoice_transactions.refunded_amount) as refunded')
            ->orderBy('invoices.currency_code')
            ->toBase()->get();

        $cents = fn ($value): int => (int) round((float) $value * 100);
        $stats = [];
        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $received = $cents($row->received);
            $fees = $cents($row->fees);
            $refunded = $cents($row->refunded);
            $suffix = $rows->count() > 1 ? " · {$currency}" : '';
            $stats[] = Stat::make(__('Received') . $suffix, ClientSummary::money($received, $currency));
            $stats[] = Stat::make(__('Fees') . $suffix, ClientSummary::money($fees, $currency));
            $stats[] = Stat::make(__('Refunded') . $suffix, ClientSummary::money($refunded, $currency));
            $stats[] = Stat::make(__('Balance') . $suffix, ClientSummary::money($received - $fees - $refunded, $currency));
        }

        return $stats;
    }
}

<?php

namespace App\Admin\Widgets;

use App\Models\BankMovement;
use App\Services\Money\GatewayBalances;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * «Bank totals» (owner 2026-10-24: one line for what WHMCS showed in three widgets): each bank account's balance from
 * its latest authentic alert, what Stripe and PayPal hold (gateways:balances, hourly), and their sum in EUR. A page
 * never calls a bank or a gateway. Its own permission: the balances are not every staff member's business.
 */
class BankTotals extends Widget
{
    // One thin line under Registrar credit, as it (owner 2026-10-24): the dashboard's grid takes one widget a cell.
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'admin.widgets.bank-totals';

    public function render(): View
    {
        $rows = [];
        $total = '0.00';
        foreach (BankMovement::balances() as $bank => $balance) {
            $rows[] = ['label' => BankMovement::BANKS[$bank] ?? $bank, 'amount' => $balance['balance'], 'currency' => 'EUR',
                // A bank with more than one account: each one's balance in the note.
                'note' => implode(' · ', array_filter([
                    count($balance['accounts']) > 1 ? implode(' · ', array_map(fn ($account, $amount) => ($account !== '' ? $account : '—') . ' ' . self::money($amount, 'EUR'),
                        array_keys($balance['accounts']), $balance['accounts'])) : null,
                    $balance['at'] ? __('Last movement :when', ['when' => $balance['at']->format('d/m/Y H:i')]) : null,
                ])) ?: null, 'error' => null];
            $total = bcadd($total, $balance['balance'], 2);
        }
        foreach (GatewayBalances::stored() as $gateway) {
            foreach ($gateway['rows'] ?: [['currency' => 'EUR', 'available' => null, 'pending' => '0.00']] as $row) {
                $pending = bccomp((string) $row['pending'], '0', 2) !== 0 ? __('pending :amount', ['amount' => self::money((string) $row['pending'], $row['currency'])]) : null;
                $read = $gateway['read_at'] ? __('Read :when', ['when' => Carbon::parse($gateway['read_at'])->diffForHumans()]) : __('Not read yet');
                $rows[] = ['label' => $gateway['label'], 'amount' => $row['available'], 'currency' => $row['currency'],
                    'note' => implode(' · ', array_filter([$pending, $read])), 'error' => $gateway['error']];
                if ($row['available'] !== null && $row['currency'] === 'EUR') {
                    $total = bcadd($total, (string) $row['available'], 2);
                }
            }
        }

        return view($this->view, ['rows' => array_map(fn ($r) => $r + ['money' => $r['amount'] === null ? '—' : self::money((string) $r['amount'], $r['currency'])], $rows),
            'total' => self::money($total, 'EUR')]);
    }

    public static function money(string $amount, string $currency): string
    {
        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.bank_totals')
            && (BankMovement::query()->exists() || GatewayBalances::stored() !== []);
    }
}

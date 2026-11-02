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
 * never calls a bank or a gateway; an enabled gateway not read yet, or that never answered, is «Not available». Its own
 * permission: the balances are not every staff member's business.
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
        // One entry a gateway (owner 2026-11-02: a line a currency, most of them 0,00, was too long): its EUR, and another
        // currency only when it holds something; what is pending on hover.
        foreach (GatewayBalances::shown() as $gateway) {
            $read = $gateway['read_at'] ? __('Read :when', ['when' => Carbon::parse($gateway['read_at'])->diffForHumans()]) : __('Not read yet');
            $money = ['EUR' => null];
            $pending = [];
            foreach ($gateway['rows'] as $row) {
                $eur = $row['currency'] === 'EUR';
                if ($eur) {
                    $total = bcadd($total, (string) $row['available'], 2);
                }
                if ($eur || bccomp((string) $row['available'], '0', 2) !== 0) {
                    $money[$row['currency']] = self::money((string) $row['available'], $row['currency']);
                }
                if (bccomp((string) $row['pending'], '0', 2) !== 0) {
                    $pending[] = __('pending :amount', ['amount' => self::money((string) $row['pending'], $row['currency'])]);
                }
            }
            $rows[] = ['label' => $gateway['label'], 'amount' => null, 'currency' => 'EUR',
                'money' => $gateway['rows'] === [] ? __('Not available') : (implode(' + ', array_filter($money)) ?: self::money('0', 'EUR')),
                'note' => implode(' · ', [...$pending, $read]), 'error' => $gateway['error']];
        }

        return view($this->view, ['rows' => array_map(fn ($r) => $r + ['money' => $r['amount'] === null ? __('Not available') : self::money((string) $r['amount'], $r['currency'])], $rows),
            'total' => self::money($total, 'EUR')]);
    }

    public static function money(string $amount, string $currency): string
    {
        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.bank_totals')
            && (BankMovement::query()->exists() || GatewayBalances::shown() !== []);
    }
}

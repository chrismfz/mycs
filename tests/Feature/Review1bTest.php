<?php

namespace Tests\Feature;

use App\Models\InvoiceTransaction;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Second independent review of fix/adv-area-1, commit 5f67e6a2 (2026-11-07). Uses AdvArea1Test's synthetic WHMCS
 * fixture. Each test fails on the commit for the finding it names (R1b-n).
 */
class Review1bTest extends AdvArea1Test
{
    private function whmcsExec(string $sql): void
    {
        (new \ReflectionProperty(AdvArea1Test::class, 'whmcs'))->getValue($this)->exec($sql);
    }

    private function client(): int
    {
        return (int) (new \ReflectionProperty(AdvArea1Test::class, 'clientId'))->getValue($this);
    }

    private function whmcsCall(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(AdvArea1Test::class, $method))->invoke($this, ...$args);
    }

    /**
     * R1b-1. A «Payment Pending» Mass Pay of 25 of children, 5 of it from account credit (WHMCS: total 20, credit 5): the
     * gateway debit on its way is 20. The children are held for 25 (each its full remaining), and the 5 of credit WHMCS
     * took from the balance is on no invoice here: an opening_balance adjustment of -5 swallows it. When the debit clears
     * (20), a child stays 5 short and is charged/reminded again, or staff record 25 of gateway money that never came; if
     * the debit fails, staff delete the holds and the customer's 5 of credit is simply gone (WHMCS gives it back when the
     * parent is cancelled).
     */
    public function test_r1b_1_a_payment_pending_mass_pay_with_credit_holds_what_the_debit_is_and_keeps_the_credit(): void
    {
        foreach ([[801, 10], [802, 15]] as [$id, $amount]) {
            $this->whmcsCall('invoice', $id, 'Unpaid', $amount);
            $this->whmcsCall('item', $id, 'Hosting', 0, $amount);
        }
        $this->whmcsExec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (900, {$this->client()}, '', '2026-10-16', '2026-10-16', '0000-00-00 00:00:00', 'Payment Pending', 20, 5, 0, 25)");
        $this->whmcsExec("INSERT INTO tblcredit (clientid, date, description, amount) VALUES
             ({$this->client()}, '2026-10-01', 'Manual credit', 5),
            ({$this->client()}, '2026-10-16', 'Credit Applied to Invoice #900', -5)");
        $this->whmcsCall('item', 900, 'Invoice', 801, 10);
        $this->whmcsCall('item', 900, 'Invoice', 802, 15);
        $this->whmcsCall('import');

        $held = (float) InvoiceTransaction::query()->where('transaction_id', 'like', 'whmcs-pending-%')->sum('amount');
        $fromCredit = (float) InvoiceTransaction::query()->whereIn('invoice_id', [801, 802])->where('is_credit_transaction', true)->sum('amount');
        $adjusted = DB::table('money_events')->where('cause', MoneyTrail::CAUSE_OPENING_BALANCE)->exists();
        $this->assertSame([20.0, 5.0, false], [$held, $fromCredit, $adjusted],
            'held for what the debit is (20), the credit placed on the children (5), no opening_balance adjustment swallowing it');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\Payment\SavedMethodCharge;
use ReflectionMethod;

/**
 * Independent review of fix/adv-area-1 (2026-11-07). Uses AdvArea1Test's synthetic WHMCS fixture. Each test fails on
 * the branch for the finding it names (R1-n). R1-1, R1-2 and R1-4 no longer apply (the owner's decision: unpaid WHMCS upgrades
 * stay charges, named and not auto-charged), dropped.
 */
class Review1Test extends AdvArea1Test
{
    private function whmcsExec(string $sql): void
    {
        $pdo = (new \ReflectionProperty(AdvArea1Test::class, 'whmcs'))->getValue($this);
        $pdo->exec($sql);
    }

    private function client(): int
    {
        return (int) (new \ReflectionProperty(AdvArea1Test::class, 'clientId'))->getValue($this);
    }

    /**
     * R1-3. A Mass Pay parent in «Payment Pending» (the customer's debit of the group on its way): importPendingPayments
     * skips Mass Pay parents, and importMassPay drops a parent with no money in. Nothing holds its children: the cron
     * charges, reminds and suspends them while the debit clears, and the importer does not name it.
     */
    public function test_r1_3_a_payment_pending_mass_pay_parent_holds_its_children(): void
    {
        foreach ([[801, 10], [802, 15]] as [$id, $amount]) {
            (new ReflectionMethod(AdvArea1Test::class, 'invoice'))->invoke($this, $id, 'Unpaid', $amount);
            (new ReflectionMethod(AdvArea1Test::class, 'item'))->invoke($this, $id, 'Hosting', 0, $amount);
        }
        $this->whmcsExec("INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, status, total, credit, taxrate, subtotal)
            VALUES (900, {$this->client()}, '', '2026-10-16', '2026-10-16', '0000-00-00 00:00:00', 'Payment Pending', 25, 0, 0, 25)");
        (new ReflectionMethod(AdvArea1Test::class, 'item'))->invoke($this, 900, 'Invoice', 801, 10);
        (new ReflectionMethod(AdvArea1Test::class, 'item'))->invoke($this, 900, 'Invoice', 802, 15);

        (new ReflectionMethod(AdvArea1Test::class, 'import'))->invoke($this, ['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney']);

        $this->assertTrue(SavedMethodCharge::onItsWay(Invoice::query()->findOrFail(801)), 'the group\'s debit is on its way: its child is held');
    }
}

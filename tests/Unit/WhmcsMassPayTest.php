<?php

namespace Tests\Unit;

use App\Services\Import\WhmcsMassPay;
use PHPUnit\Framework\TestCase;

/**
 * PR F3: a WHMCS Mass Pay parent as MyCS keeps it. The payment lands on the child due first, what that one cannot take
 * pays the others as allocations of the payment, the part paid from account credit pays the rest.
 */
class WhmcsMassPayTest extends TestCase
{
    /** @return array<int, array{status: string, duedate: ?string, credit: int}> */
    private function children(array $credits, array $dues = []): array
    {
        $children = [];
        foreach ($credits as $id => $credit) {
            $children[$id] = ['status' => 'Paid', 'duedate' => $dues[$id] ?? '2026-09-0' . ($id % 10), 'credit' => $credit];
        }

        return $children;
    }

    public function test_one_payment_lands_on_the_child_due_first_and_pays_the_others(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 900, 'total' => 4500, 'credit' => 0],
            [['child' => 11, 'amount' => 1000], ['child' => 12, 'amount' => 1500], ['child' => 13, 'amount' => 2000]],
            $this->children([11 => 1000, 12 => 1500, 13 => 2000], [11 => '2026-09-05', 12 => '2026-09-06', 13 => '2026-09-04']),
            [['id' => 70, 'amount' => 4500, 'date' => '2026-09-03 10:00:00']]);

        $this->assertSame([['id' => 70, 'invoice_id' => 13, 'credited' => 2500]], $plan['payments']);
        $this->assertSame([
            ['invoice_id' => 11, 'amount' => 1000, 'source_id' => 70, 'date' => '2026-09-03 10:00:00'],
            ['invoice_id' => 12, 'amount' => 1500, 'source_id' => 70, 'date' => '2026-09-03 10:00:00'],
        ], $plan['allocations']);
        $this->assertSame([13, 11, 12], array_column($plan['group']['invoices'], 'invoice_id'));
        $this->assertSame([13, 4500, 70], [$plan['group']['invoice_id'], $plan['group']['total'], $plan['group']['transaction_id']]);
        $this->assertSame([], $plan['warnings']);
    }

    public function test_the_part_paid_from_account_credit_pays_the_rest_without_a_source(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 901, 'total' => 3000, 'credit' => 500],
            [['child' => 21, 'amount' => 1000], ['child' => 22, 'amount' => 2000]],
            $this->children([21 => 1000, 22 => 2000]),
            [['id' => 71, 'amount' => 2500, 'date' => '2026-09-03 10:00:00']]);

        $this->assertSame([['id' => 71, 'invoice_id' => 21, 'credited' => 1500]], $plan['payments']);
        $this->assertSame([
            ['invoice_id' => 22, 'amount' => 1500, 'source_id' => 71, 'date' => '2026-09-03 10:00:00'],
            ['invoice_id' => 22, 'amount' => 500, 'source_id' => null, 'date' => '2026-09-03 10:00:00'],
        ], $plan['allocations']);
        $this->assertSame([], $plan['warnings']);
    }

    public function test_a_parent_paid_only_from_credit_has_no_payment(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 902, 'total' => 3000, 'credit' => 3000],
            [['child' => 31, 'amount' => 1000], ['child' => 32, 'amount' => 2000]],
            $this->children([31 => 1000, 32 => 2000]), []);

        $this->assertSame([], $plan['payments']);
        $this->assertSame([[31, 1000, null], [32, 2000, null]], array_map(fn (array $a) => [$a['invoice_id'], $a['amount'], $a['source_id']], $plan['allocations']));
        $this->assertNull($plan['group']['transaction_id']);
    }

    public function test_two_payments_fill_the_children_in_order(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 903, 'total' => 3000, 'credit' => 0],
            [['child' => 41, 'amount' => 1000], ['child' => 42, 'amount' => 2000]],
            $this->children([41 => 1000, 42 => 2000]),
            [['id' => 81, 'amount' => 2000, 'date' => '2026-09-04 10:00:00'], ['id' => 80, 'amount' => 1000, 'date' => '2026-09-03 10:00:00']]);

        $this->assertSame([['id' => 80, 'invoice_id' => 41, 'credited' => 0], ['id' => 81, 'invoice_id' => 42, 'credited' => 0]], $plan['payments']);
        $this->assertSame([], $plan['allocations']);
    }

    public function test_a_child_paid_in_part_elsewhere_takes_only_what_was_applied_and_staff_are_told(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 904, 'total' => 3000, 'credit' => 0],
            [['child' => 51, 'amount' => 1000], ['child' => 52, 'amount' => 2000]],
            $this->children([51 => 1000, 52 => 1500]),
            [['id' => 90, 'amount' => 3000, 'date' => '2026-09-03 10:00:00']]);

        $this->assertSame(1500, $plan['allocations'][0]['amount']);
        $this->assertSame(500, $plan['payments'][0]['credited'] - $plan['allocations'][0]['amount'], 'what no child took stays account credit');
        $this->assertCount(2, $plan['warnings']);
    }

    public function test_a_total_that_is_not_its_lines_and_a_missing_child_are_reported(): void
    {
        $plan = WhmcsMassPay::plan(['id' => 905, 'total' => 3100, 'credit' => 0],
            [['child' => 61, 'amount' => 1000], ['child' => 62, 'amount' => 2000]],
            $this->children([61 => 1000]),
            [['id' => 91, 'amount' => 1000, 'date' => '2026-09-03 10:00:00']]);

        $this->assertSame([61], array_column($plan['group']['invoices'], 'invoice_id'));
        $this->assertCount(2, $plan['warnings']);
    }

    public function test_cents_reads_whmcs_decimals(): void
    {
        $this->assertSame([1157, 0, 4553], [WhmcsMassPay::cents('11.57'), WhmcsMassPay::cents(null), WhmcsMassPay::cents(45.53)]);
    }
}

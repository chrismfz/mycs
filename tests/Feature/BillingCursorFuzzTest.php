<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Randomized simulation (property-based) of the billing cursor (docs/billing-cursor.md, PR #32).
 *
 * Per seed: one service (monthly, some yearly, some on the 29th/30th/31st) lives FUZZ_MONTHS (10; yearly FUZZ_YEARS 3)
 * while app:cron-job runs every few days and random events happen: payments (twice, reverse order, on a
 * cancelled invoice), cancellations, staff moving the Next Due Date, period-less manual invoices, silent
 * customers (suspend/terminate), zero-price months. Invariants I1..I7 are checked after every step.
 *
 * FUZZ_SEEDS="1,2,3" or FUZZ_SEEDS="1-200" overrides the seed list. On failure the seed and event log print.
 * A payment on a cancelled invoice no longer revives it (PR 2a, docs/payments.md), so every violation fails
 * the run; FUZZ_LENIENT=1 counts those that involve one as KNOWN instead (the pre-2a behaviour).
 */
class BillingCursorFuzzTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    // ---- per-seed state
    private Service $service;

    private string $unit;

    private int $months;

    private int $anchor;

    private bool $staffMoved;

    private string $maxStaff;

    private array $free;

    private array $revived;

    private array $knownItems;

    private array $log;

    private array $known = [];

    private array $everPaid = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
            'settings.cronjob_order_cancel' => 7,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function seeds(): array
    {
        $env = getenv('FUZZ_SEEDS');
        if ($env === false || $env === '') {
            return range(1, 20); // CI; deeper: FUZZ_SEEDS="1-200"
        }
        if (preg_match('/^(\d+)-(\d+)$/', $env, $m)) {
            return range((int) $m[1], (int) $m[2]);
        }

        return array_map('intval', explode(',', $env));
    }

    // =====================================================================================  the simulation

    public function test_randomized_billing_simulation(): void
    {
        $failures = [];
        $steps = 0;
        $t0 = microtime(true);
        foreach (self::seeds() as $seed) {
            [$failure, $n] = $this->runSeed($seed);
            $steps += $n;
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        $summary = sprintf("fuzz: %d seeds, %d steps, %.1fs, %d failing, %d known(PR2)\n",
            count(self::seeds()), $steps, microtime(true) - $t0, count($failures), count($this->known));
        fwrite(STDERR, $summary);
        foreach ($this->known as $k) {
            fwrite(STDERR, "KNOWN $k\n");
        }
        foreach ($failures as $f) {
            fwrite(STDERR, "\n" . $f . "\n");
        }

        $this->assertSame([], array_map(fn ($f) => strtok($f, "\n"), $failures), $summary);
    }

    /** @return array{0: ?string, 1: int} */
    private function runSeed(int $seed): array
    {
        mt_srand($seed);
        $this->log = [];
        $this->free = [];
        $this->revived = [];
        $this->everPaid = [];
        $this->staffMoved = false;

        $yearly = $seed % 5 === 4;
        $this->unit = $yearly ? 'year' : 'month';
        $this->months = $yearly ? 12 : 1;
        $day = match ($seed % 4) {
            1 => 31, 2 => 30, 3 => 29, default => mt_rand(1, 28),
        };
        $history = mt_rand(1, 10) <= 7;

        if ($yearly && $day === 29) {
            $expires = CarbonImmutable::parse('2028-02-29');
        } else {
            $month = CarbonImmutable::create(2027, mt_rand(1, 12), 1);
            $expires = $month->setDay(min($day, $month->daysInMonth));
        }
        // Without a history line the billing day can only be the expiry's own day.
        $this->anchor = $history ? $day : $expires->day;
        if (!$history && $expires->day !== $day) {
            $day = $expires->day;
        }

        Carbon::setTestNow($expires->subDays(mt_rand(0, 10))->setTime(10, 0));
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['billing_unit' => $this->unit, 'billing_period' => 1]);
        $this->service = Service::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => $expires->toDateString(),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);
        $this->maxStaff = $expires->toDateString();
        if ($history) {
            $prev = $expires->subMonthsNoOverflow($this->months);
            $prev = $prev->setDay(min($this->anchor, $prev->daysInMonth));
            $this->invoiceFor($prev->toDateString(), $expires->toDateString(), 'paid');
        }
        $this->knownItems = $this->itemIds();
        $this->note("seed=$seed unit={$this->unit} day=$day history=" . ($history ? 'y' : 'n') . " expires={$expires->toDateString()}");

        $horizon = CarbonImmutable::now()->addMonths($yearly ? (int) (getenv('FUZZ_YEARS') ?: 3) * 12 : (int) (getenv('FUZZ_MONTHS') ?: 10));
        $silentUntil = null;
        $deadSteps = 0;
        $n = 0;
        $failure = null;

        while (CarbonImmutable::now()->lessThan($horizon)) {
            $n++;
            Carbon::setTestNow(CarbonImmutable::now()->addDays($yearly ? mt_rand(10, 25) : mt_rand(3, 7)));
            $silent = $silentUntil !== null && CarbonImmutable::now()->lessThan($silentUntil);
            if (!$silent && mt_rand(1, 100) <= 3) {
                $silentUntil = CarbonImmutable::now()->addDays(mt_rand(8, 45));
                $this->note('customer goes silent until ' . $silentUntil->toDateString());
            }

            $actions = ['cron'];
            if (!$silent && mt_rand(1, 100) <= 60) {
                $actions[] = 'pay_oldest';
            }
            if (mt_rand(1, 100) <= 30) {
                $actions[] = $this->pick([
                    'pay_random' => 4, 'pay_twice' => 2, 'pay_cancelled' => 1, 'cancel' => 3, 'staff_move' => 3,
                    'manual_line' => 2, 'reverse_pay' => 2, 'price_toggle' => 2, 'repay' => 1, 'cancel_paid' => 1,
                ]);
            }
            shuffle($actions);

            foreach ($actions as $action) {
                try {
                    $failure = $this->step($action, $silent);
                } catch (Throwable $e) {
                    $failure = 'I7 exception: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
                }
                if ($failure !== null) {
                    break 2;
                }
            }

            if ($this->service->fresh()->status === 'cancelled' && ++$deadSteps > 8) {
                break;
            }
        }

        // Retire the service so later seeds' cron runs ignore it.
        DB::table('services')->where('id', $this->service->id)->update(['status' => 'cancelled', 'expires_at' => null]);
        DB::table('invoices')->whereIn('id', InvoiceItem::query()->where('reference_type', Service::class)
            ->where('reference_id', $this->service->id)->pluck('invoice_id'))->update(['status' => 'cancelled']);

        if ($failure === null) {
            return [null, $n];
        }

        return ["SEED $seed: $failure\n  " . implode("\n  ", array_slice($this->log, -60)), $n];
    }

    /** One action, then all invariants. Returns a violation or null. */
    private function step(string $action, bool $silent): ?string
    {
        $before = $this->state();
        $staffMoveBack = false;
        $paid = false;
        $replay = false;
        $detail = '';
        $wasFreeCron = false;

        switch ($action) {
            case 'cron':
                $wasFreeCron = (float) $this->service->fresh()->price <= 0;
                Artisan::call('app:cron-job');
                break;
            case 'pay_oldest':
            case 'pay_random':
            case 'pay_twice':
                $pending = $this->invoices('pending');
                if ($pending->isEmpty()) {
                    return null;
                }
                $inv = $action === 'pay_oldest' ? $pending->first() : $pending->random();
                $this->pay($inv);
                if ($action === 'pay_twice') {
                    $this->pay($inv->fresh());
                }
                $paid = true;
                $detail = "#{$inv->id}";
                break;
            case 'reverse_pay':
                $pending = $this->invoices('pending');
                if ($pending->count() < 2) {
                    return null;
                }
                $this->pay($pending->last());
                $this->pay($pending->first());
                $paid = true;
                $detail = "#{$pending->last()->id} then #{$pending->first()->id}";
                break;
            case 'pay_cancelled':
                $cancelled = $this->invoices('cancelled');
                if ($cancelled->isEmpty()) {
                    return null;
                }
                $inv = $cancelled->random();
                $this->pay($inv);
                if ($inv->fresh()->status === 'paid') {
                    foreach ($inv->items as $item) {
                        $this->revived[$item->id] = true;
                    }
                }
                $paid = true;
                $detail = "#{$inv->id}";
                break;
            case 'cancel':
                $pending = $this->invoices('pending');
                if ($pending->isEmpty()) {
                    return null;
                }
                $inv = $pending->random();
                $inv->update(['status' => 'cancelled']);
                $detail = "#{$inv->id}";
                break;
            case 'staff_move':
                $s = $this->service->fresh();
                if ($s->status === 'cancelled' || $s->expires_at === null) {
                    return null;
                }
                $delta = mt_rand(1, mt_rand(0, 3) ? 40 : 75) * (mt_rand(0, 1) ? 1 : -1);
                $target = CarbonImmutable::parse($s->expires_at)->addDays($delta)->toDateString();
                $s->update(['expires_at' => $target]);
                $after = $this->service->fresh()->expires_at->toDateString();
                $staffMoveBack = $delta < 0;
                $this->staffMoved = true;
                $this->maxStaff = max($this->maxStaff, $after);
                $detail = "{$delta}d to $target (stored $after)";
                break;
            case 'repay': // admin flips a paid invoice back to pending and to paid again
                $done = $this->invoices('paid');
                if ($done->isEmpty()) {
                    return null;
                }
                $inv = $done->random();
                $replay = $inv->items->every(fn ($item) => $item->applied_at !== null);
                $inv->update(['status' => 'pending']);
                $inv->fresh()->update(['status' => 'paid']);
                $paid = !$replay;
                $detail = "#{$inv->id}";
                break;
            case 'cancel_paid': // staff cancel a paid invoice (no refund logic yet: A6)
                $done = $this->invoices('paid');
                if ($done->isEmpty()) {
                    return null;
                }
                $inv = $done->random();
                $inv->update(['status' => 'cancelled']);
                $detail = "#{$inv->id}";
                break;
            case 'manual_line':
                $inv = $this->invoiceFor(null, null, 'pending');
                $detail = "#{$inv->id} (no period)";
                break;
            case 'price_toggle':
                $s = $this->service->fresh();
                $s->update(['price' => (float) $s->price > 0 ? 0 : 10]);
                $detail = 'price=' . $s->fresh()->price;
                break;
        }

        $after = $this->state();
        // I7: an already processed line is not a new payment, including after staff moves the date back.
        if ($replay && ($after['expires'] !== $before['expires'] || $after['status'] !== $before['status'])) {
            return 'I7 replay changed the expiry or service status';
        }
        if ($wasFreeCron && $after['expires'] !== null && $before['expires'] !== null && $after['expires'] > $before['expires']) {
            // A free renewal adds one period from what is paid (paid periods after the expiry are followed first).
            $paidBefore = array_filter($before['lines'], fn ($l) => $l['status'] === 'paid' && $l['s'] !== null);
            $from = $this->chain($before['expires'], $paidBefore);
            $this->free[] = [$from, $after['expires']];
            $detail .= " free {$from}->{$after['expires']}";
        }
        $this->note(sprintf('%s %-13s %s => exp=%s %s %s', CarbonImmutable::now()->toDateString(), $action, $detail,
            $after['expires'] ?? 'null', $after['status'], $this->describeLines($after['lines'])));

        return $this->check($action, $before, $after, $staffMoveBack, $paid, $wasFreeCron);
    }

    // =====================================================================================  invariants

    private function check(string $action, array $before, array $after, bool $staffMoveBack, bool $paid, bool $freeCron): ?string
    {
        $exp = $after['expires'];
        $paidLines = array_values(array_filter($after['lines'], fn ($l) => $l['status'] === 'paid' && $l['s'] !== null));
        $live = array_values(array_filter($after['lines'], fn ($l) => $l['status'] !== 'cancelled' && $l['s'] !== null));
        $revivedInvolved = fn (array $ids) => (bool) array_intersect_key($this->revived, array_flip($ids));
        $allRevivedIds = array_keys($this->revived);

        // I5 a cancelled service is never set active by a payment
        if ($before['status'] === 'cancelled' && $after['status'] !== 'cancelled') {
            return "I5 cancelled service became {$after['status']} after $action";
        }

        // I1 expires_at never decreases except by an explicit staff move back
        if (!$staffMoveBack && $before['expires'] !== null && ($exp === null || $exp < $before['expires'])) {
            return "I1 expires_at decreased {$before['expires']} -> " . ($exp ?? 'null') . " on $action";
        }

        foreach ($paidLines as $l) {
            $this->everPaid[$l['id']] = [$l['s'], $l['e']]; // a paid invoice staff later cancel still paid (A6)
        }
        $justified = array_merge(array_values($this->everPaid), $this->free);

        // I2 paid-until never covers a day of a live unpaid period line unless staff moved the date there
        if ($exp !== null) {
            foreach ($live as $l) {
                if ($l['status'] === 'paid' || $l['s'] >= $exp) {
                    continue;
                }
                $hole = $this->firstUncovered(max($l['s'], $this->maxStaff), min($l['e'], $exp), $justified);
                if ($hole !== null) {
                    return "I2 expires_at=$exp covers $hole of unpaid line #{$l['id']} [{$l['s']},{$l['e']}) (inv {$l['status']}) after $action";
                }
            }
        }

        // I3a no day of paid-until comes from nowhere (each paid line counts at most once)
        if ($exp !== null && $exp > $this->maxStaff) {
            $hole = $this->firstUncovered($this->maxStaff, $exp, $justified);
            if ($hole !== null) {
                $msg = "I3a expires_at=$exp but day $hole is not paid, free, or staff-given (after $action)";

                return $this->knownOr($msg, $allRevivedIds, $revivedInvolved);
            }
        }

        // I3b no paid days are lost: after a payment paid-until is a fixpoint of the paid chain (not after a
        // staff move back, which stays until a NEW payment, never a replay: decision A3)
        if ($paid && $exp !== null && $after['status'] !== 'cancelled') {
            $chained = $this->chain($exp, $paidLines);
            if ($chained !== $exp) {
                return "I3b paid lines chain from expires_at=$exp to $chained but expires_at stayed (after $action)";
            }
        }

        // I4 + A4: lines new this step (cron-created, or period assigned at payment) overlap no other live line
        $newIds = [];
        foreach ($after['lines'] as $l) {
            $old = $before['lines'][$l['id']] ?? null;
            if ($l['s'] !== null && ($old === null || $old['s'] === null)) {
                $newIds[] = $l['id'];
            }
        }
        foreach ($newIds as $id) {
            $l = $after['lines'][$id];
            if ($l['status'] === 'cancelled') {
                continue;
            }
            foreach ($live as $o) {
                if ($o['id'] !== $id && $o['s'] < $l['e'] && $l['s'] < $o['e']) {
                    $msg = "I4 new line #$id [{$l['s']},{$l['e']}) overlaps live #{$o['id']} [{$o['s']},{$o['e']}) {$o['status']} (after $action)";
                    $r = $this->knownOr($msg, [$o['id']], $revivedInvolved);
                    if ($r !== null) {
                        return $r;
                    }
                }
            }

            // I6 one billing cycle per line; a hole shorter than a cycle (it ends where the next live
            // line starts, e.g. after a staff move back before a paid period) is billed alone, prorated.
            $hole = collect($live)->contains(fn ($o) => $o['id'] !== $id && $o['s'] === $l['e']);
            $bad = $hole ? null : $this->badPeriod($l['s'], $l['e'], $action === 'cron' && !$this->staffMoved);
            if ($bad !== null) {
                return "I6 line #$id [{$l['s']},{$l['e']}) $bad (after $action)";
            }
        }
        if ($freeCron && $this->free && end($this->free)[1] === $exp && $before['expires'] !== $exp) {
            [$fs, $fe] = end($this->free);
            $bad = $this->badPeriod($fs, $fe, false);
            if ($bad !== null) {
                return "I6 free renewal [$fs,$fe) $bad";
            }
        }

        return null;
    }

    private function knownOr(string $msg, array $ids, callable $revivedInvolved): ?string
    {
        if ($revivedInvolved($ids) && getenv('FUZZ_LENIENT')) {
            $this->known[] = $msg;

            return null;
        }

        return $msg;
    }

    /** Period length is one cycle: same target month, day kept (or clamped); $strict also checks drift. */
    private function badPeriod(string $s, string $e, bool $strict): ?string
    {
        $start = CarbonImmutable::parse($s);
        $end = CarbonImmutable::parse($e);
        $target = $start->addMonthsNoOverflow($this->months);
        if ($end->year !== $target->year || $end->month !== $target->month) {
            return "is not one {$this->unit} (expected end in {$target->format('Y-m')})";
        }
        $ambiguous = $start->day === $start->daysInMonth && $start->daysInMonth < 31;
        if (!$ambiguous && $end->day !== min($start->day, $end->daysInMonth)) {
            return "end day {$end->day} != start day {$start->day} (clamped)";
        }
        if ($ambiguous && $end->day < min($start->day, $end->daysInMonth)) {
            return 'end day before start day';
        }
        if ($strict) {
            $feb29 = $this->unit === 'year' && $this->anchor === 29; // documented: yearly 29/2 stays on 28/2
            foreach ([$start, $end] as $d) {
                if (!$feb29 && $d->day !== min($this->anchor, $d->daysInMonth)) {
                    return "drifted off billing day {$this->anchor} ({$d->toDateString()})";
                }
            }
        }

        return null;
    }

    /** First day in [$from, $to) not covered by the intervals, or null. */
    private function firstUncovered(string $from, string $to, array $intervals): ?string
    {
        $cursor = $from;
        while ($cursor < $to) {
            $moved = false;
            foreach ($intervals as [$s, $e]) {
                if ($s <= $cursor && $e > $cursor) {
                    $cursor = $e;
                    $moved = true;
                }
            }
            if (!$moved) {
                return $cursor;
            }
        }

        return null;
    }

    private function chain(string $from, array $lines): string
    {
        return $this->firstUncovered($from, '9999-12-31', array_map(fn ($l) => [$l['s'], $l['e']], $lines)) ?? $from;
    }

    // =====================================================================================  helpers

    private function state(): array
    {
        $s = $this->service->fresh();
        $lines = [];
        foreach (InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $s->id)->with('invoice')->orderBy('id')->get() as $item) {
            $lines[$item->id] = [
                'id' => $item->id,
                'inv' => $item->invoice_id,
                'status' => $item->invoice->status,
                's' => $item->period_start?->toDateString(),
                'e' => $item->period_end?->toDateString(),
            ];
        }

        return ['expires' => $s->expires_at?->toDateString(), 'status' => $s->status, 'lines' => $lines];
    }

    private function describeLines(array $lines): string
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = sprintf('#%d:%s[%s,%s)', $l['id'], substr($l['status'], 0, 2), $l['s'] ?? '-', $l['e'] ?? '-');
        }

        return implode(' ', array_slice($out, -6));
    }

    private function itemIds(): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $this->service->id)->pluck('id')->all();
    }

    private function invoices(string $status)
    {
        return Invoice::query()->where('status', $status)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $this->service->id))
            ->orderBy('id')->get();
    }

    private function pick(array $weights): string
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }

        return array_key_first($weights);
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function invoiceFor(?string $start, ?string $end, string $status = 'pending', ?Service $service = null): Invoice
    {
        $service ??= $this->service;
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => 10.00,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->remaining]);
    }

    // =====================================================================================  minimized cases
    // Shortest event sequences the simulation found. A FAILING test here is a confirmed violation.

    private function fixedService(string $expires, array $attributes = [], string $unit = 'month'): Service
    {
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['billing_unit' => $unit, 'billing_period' => 1]);

        return $this->service = Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => $expires,
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function pendingLine(Service $service): InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))->sole();
    }

    /**
     * I6 (seeds 21, 27). The billing day is only read back from LIVE period lines: once the last period that
     * started on the 31st is on a cancelled invoice, a service on the 31st bills 30th→30th for good.
     */
    public function test_min_cancelling_the_last_full_day_period_keeps_the_billing_day(): void
    {
        Carbon::setTestNow('2027-04-25 10:00:00');
        $service = $this->fixedService('2027-04-30');
        $march = $this->invoiceFor('2027-03-31', '2027-04-30', 'paid', $service);
        $march->update(['status' => 'cancelled']); // staff cancel it (or it was cancelled and then comped)

        Artisan::call('app:cron-job');

        $this->assertSame('2027-05-31', $this->pendingLine($service)->period_end->toDateString());
    }

    /** I6 (free renewals write no line): a zero-price service on the 31st drifts to the 28th after February. */
    public function test_min_free_renewals_keep_the_billing_day(): void
    {
        Carbon::setTestNow('2027-01-28 10:00:00');
        $service = $this->fixedService('2027-01-31', ['price' => 0]);
        Artisan::call('app:cron-job');
        $this->assertSame('2027-02-28', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2027-02-25 10:00:00');
        Artisan::call('app:cron-job');

        $this->assertSame('2027-03-31', $service->fresh()->expires_at->toDateString());
    }

    /**
     * I4. Staff move the Next Due Date back to before the start of a paid period (more than a period back,
     * or into a gap): the chain does not reach the paid line (it starts after the cursor), so the cron bills a
     * full month that overlaps the paid one, and the customer pays those days twice.
     */
    public function test_min_moving_the_date_back_before_a_paid_period_does_not_bill_it_again(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $service = $this->fixedService('2026-11-15');
        $this->invoiceFor('2026-10-15', '2026-11-15', 'paid', $service);
        $service->update(['expires_at' => '2026-10-10']); // correction: 5 days owed before the paid period

        Artisan::call('app:cron-job');

        $line = $this->pendingLine($service);
        $this->assertFalse($line->period_end->greaterThan('2026-10-15'),
            "billed [{$line->period_start->toDateString()}, {$line->period_end->toDateString()}) over the paid [2026-10-15, 2026-11-15)");
    }

    /**
     * I4 + I3 (seeds 67, 100, 232, 240 in 41-240 x 24 months). Staff give 2 days of grace, the cron bills from
     * the new date, staff revert the grace: the paid invoice now starts 2 days after paid-until, so the payment
     * adds nothing (ahead of an unpaid gap), the service is suspended, and the cron bills a full month over
     * the period the customer just paid.
     */
    public function test_min_reverting_a_grace_move_does_not_rebill_the_paid_period(): void
    {
        Carbon::setTestNow('2027-07-16 10:00:00');
        $service = $this->fixedService('2027-07-20');
        $this->invoiceFor('2027-06-20', '2027-07-20', 'paid', $service);
        $service->update(['expires_at' => '2027-07-22']); // 2 days of grace
        Artisan::call('app:cron-job');                     // bills [07-22, 08-22)
        $service->fresh()->update(['expires_at' => '2027-07-20']); // grace reverted
        $this->pay($this->pendingLine($service)->invoice);

        Carbon::setTestNow('2027-07-27 10:00:00');
        Artisan::call('app:cron-job');

        $this->assertSame('2027-07-20', $service->fresh()->expires_at->toDateString()); // the payment bought nothing
        $line = $this->pendingLine($service);
        $this->assertFalse($line->period_end->greaterThan('2027-07-22'),
            "billed [{$line->period_start->toDateString()}, {$line->period_end->toDateString()}) over the paid [2027-07-22, 2027-08-22); status=" . $service->fresh()->status);
    }

    /**
     * Fixed in PR 2a (docs/payments.md): a cancelled invoice carries a credit note for its total, so its
     * remaining is 0 and ANY transaction (even 0.00) flips it to paid, which renews the period for nothing.
     */
    public function test_min_a_zero_transaction_on_a_cancelled_invoice_buys_no_period(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $service = $this->fixedService('2026-10-15');
        $invoice = $this->invoiceFor('2026-10-15', '2026-11-15', 'pending', $service);
        $invoice->update(['status' => 'cancelled']);

        $invoice->fresh()->transactions()->create(['amount' => 0]);

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
    }
}

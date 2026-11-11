<?php

namespace App\Services\Import;

use App\Models\Invoice;
use App\Models\Service;
use App\Services\Order\OrderPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Throwable;

/**
 * The period of the two WHMCS lines every rule here asks about (owner 2026-11-11, sweep 8): a line imported from WHMCS
 * carries no period, and whoever asks «which period did this line pay?» has to guess. The rule is «write only where it
 * is exact, name the rest». For each LIVE service (active or suspended) on a recurring plan with a due date, from
 * WHMCS's own `tblinvoiceitems.duedate` (the start of the period the line bills):
 *  - its last paid line (the latest duedate among its lines on paid invoices) takes `duedate .. due date` and is marked
 *    applied as of the day its invoice was paid, only when one cycle on from the duedate IS the service's due date, by
 *    this app's own arithmetic (Service::periodEnd). A pro rata product's first order, its only paid line and due the day
 *    the service was made, takes `that day .. due date` only when the due date is the end of the first period this app
 *    gives that order (OrderPricing::firstPeriod);
 *  - its open renewal (its ONLY unpaid line, due ON the service's due date) takes `due date .. one cycle on`, not applied.
 * Never the paid line when WHMCS upgraded or downgraded the service since the line was billed (its invoice's date: the
 * line is not what those days were paid, REV-788-4, REV-788-R2-1), nor one on an invoice that comes to 0.00 or holds no
 * money (a 100% promotion, closed as paid with no payment: its days were paid nothing, REV-788-R2-2). Never the open renewal when the service has another unpaid line (a payment of the later one
 * must still take the first month not paid for, REV-788-3), nor when an unpaid line of the service is on another
 * customer's invoice (the service moved to this one in WHMCS and the invoice stayed with the first: the billing would read
 * the period as billed and never ask the service's own customer for it, S9-IMPORT-PERIODS-1). The last paid line keeps
 * its period whoever's invoice it is on: those days were paid, and a refund of it must not give up the next period
 * (REV-804-2).
 * A WHMCS discount line beside the line (a client group's, a promotion's: a negative line of its own) changes nothing
 * here: the line gets its period all the same, with two known consequences the owner accepted (2026-11-11,
 * docs/importer.md): the days left of that period are worth the line's amount before the discount, and a plan change
 * rebuilds such an open renewal at the service's price beside its discount line.
 * Anything else stays as it came (no period, as before) and the service is named for staff, by reason. An addon imported
 * as a service of its own (WhmcsAddonImport, its «Addon» lines) goes by the same rule, without the pro rata case. Domains
 * have their own (WhmcsDomainImport).
 *
 * After the lines are in: the rows are read here, so only what was imported is ever written, and the open renewal's end
 * sees the paid line before it, as the cron will.
 */
final class WhmcsLinePeriods
{
    /** Why a line got no period, as the import says it (in this order). */
    public const REASONS = [
        'dates' => 'the last paid line is not one cycle (or a pro rata first period) before the due date',
        'upgrade' => 'upgraded or downgraded in WHMCS inside the last paid period',
        'no_money' => 'the last paid line is on an invoice of 0.00, or one with no money on it',
        'no_due' => 'the last paid line has no due date',
        'no_paid' => 'no paid line',
        'unpaid' => 'more than one unpaid line: the open renewal is not written',
        'other_client' => "a line on another customer's invoice",
    ];

    /** @var array<string, array<int, string>> reason => service => the detail of it, if any */
    private array $named = [];

    /** @var array<int, string|null> WHMCS service => the day of its latest completed upgrade or downgrade (null: no day kept) */
    private array $upgraded = [];

    /** @var array{paid: int, prorata: int, open: int} */
    private array $counts = ['paid' => 0, 'prorata' => 0, 'open' => 0];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array{paid: int, prorata: int, open: int, services: int}, named: array<string, array<int, string>>, notes: list<string>} */
    public function run(): array
    {
        $services = Service::query()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->whereNotNull('expires_at')
            ->whereHas('plan', fn ($plan) => $plan->where('type', 'recurring'))->with(['plan', 'product'])->orderBy('id')->get()->keyBy('id');
        $none = ['counts' => $this->counts + ['services' => $services->count()], 'named' => [], 'notes' => []];
        if ($services->isEmpty()) {
            return $none;
        }
        $lines = $this->lines($services->keys()->all());
        $ids = [];
        foreach ($lines as $ofService) {
            $ids += array_fill_keys(array_keys($ofService), true);
        }
        try {
            $due = $this->lineDueDates($ids);
            $this->upgrades();
            $this->moneyless = $this->moneyless();
        } catch (PDOException $e) {
            // Not a WHMCS this importer knows (every one keeps the column): nothing written, and said.
            return ['notes' => ["The lines' due dates (tblinvoiceitems.duedate) could not be read: no invoice line was given its period ({$e->getMessage()})."]] + $none;
        }
        foreach ($services as $id => $service) {
            $this->service($service, array_map(fn (object $line) => ['id' => (int) $line->id, 'invoice' => (int) $line->invoice_id, 'paid' => $line->status === Invoice::STATUS_PAID,
                'at' => (string) ($line->paid_at ?? $line->created_at ?? now()->toDateTimeString()), 'billed' => self::day($line->created_at), 'due' => $due[(int) $line->id] ?? null,
                // The one place a line's customer is asked: its invoice's is not the service's.
                'other' => (int) $line->client_id !== (int) $service->client_id], $lines[$id] ?? []));
        }
        $named = [];
        foreach (array_keys(self::REASONS) as $reason) {
            if (isset($this->named[$reason])) {
                ksort($this->named[$reason]);
                $named[$reason] = $this->named[$reason];
            }
        }

        return ['counts' => $this->counts + ['services' => $services->count()], 'named' => $named, 'notes' => []];
    }

    /**
     * The lines that bill a period of these services (no kind), on paid and unpaid invoices. With or without a period
     * already: run again on the same import, the same two lines are found and written the same.
     *
     * @param  list<int>  $services
     * @return array<int, array<int, object>> service => line id => the line with its invoice's customer, status and dates
     */
    private function lines(array $services): array
    {
        $byService = [];
        foreach (array_chunk($services, 500) as $chunk) {
            $rows = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.reference_type', Service::class)->whereIn('invoice_items.reference_id', $chunk)
                ->whereNull('invoice_items.kind')
                ->whereIn('invoices.status', [Invoice::STATUS_PAID, Invoice::STATUS_UNPAID])
                ->orderBy('invoice_items.id')
                ->get(['invoice_items.id', 'invoice_items.invoice_id', 'invoice_items.reference_id', 'invoices.client_id', 'invoices.status', 'invoices.paid_at', 'invoices.created_at']);
            foreach ($rows as $row) {
                $byService[(int) $row->reference_id][(int) $row->id] = $row;
            }
        }

        return $byService;
    }

    /**
     * WHMCS's due date of each of these lines (the lines keep their WHMCS ids), as Y-m-d; none for an empty one.
     *
     * @param  array<int, true>  $ids
     * @return array<int, string>
     */
    private function lineDueDates(array $ids): array
    {
        $due = [];
        $statement = $this->whmcs->query("SELECT id, duedate FROM tblinvoiceitems WHERE type IN ('Hosting', 'Addon') ORDER BY id");
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (isset($ids[(int) $row['id']]) && ($day = self::day($row['duedate'])) !== null) {
                $due[(int) $row['id']] = $day;
            }
        }

        return $due;
    }

    /**
     * WHMCS's own upgrades and downgrades of a package or its options, done: the latest day of each service's. A WHMCS
     * without the table (a fixture) has none; one that keeps no day on them counts each as inside the period.
     */
    private function upgrades(): void
    {
        $where = "WHERE status = 'Completed' AND type IN ('package', 'configoptions')";
        try {
            $rows = $this->whmcs->query("SELECT relid, date FROM tblupgrades {$where}")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            try {
                $rows = $this->whmcs->query("SELECT relid, NULL AS date FROM tblupgrades {$where}")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException) {
                return;
            }
        }
        foreach ($rows as $row) {
            $id = (int) $row['relid'];
            $day = self::day($row['date']);
            if (!array_key_exists($id, $this->upgraded)) {
                $this->upgraded[$id] = $day;
            } elseif ($this->upgraded[$id] !== null) {
                $this->upgraded[$id] = $day === null ? null : max($day, $this->upgraded[$id]);
            }
        }
    }

    /** @var array<int, true> WHMCS invoices that come to 0.00 or hold no money */
    private array $moneyless = [];

    /**
     * WHMCS's paid invoices that bill nothing (total and credit used come to 0.00 or less) or hold no money: no credit
     * used, no payment of their own, and not paid through a Mass Pay invoice (an «Invoice» line of another names them).
     *
     * @return array<int, true>
     */
    private function moneyless(): array
    {
        try {
            $paidOn = array_fill_keys(array_map('intval', $this->whmcs->query('SELECT invoiceid FROM tblaccounts GROUP BY invoiceid HAVING SUM(COALESCE(amountin, 0) - COALESCE(amountout, 0)) > 0')
                ->fetchAll(PDO::FETCH_COLUMN)), true);
            $invoices = $this->whmcs->query("SELECT id, total, credit FROM tblinvoices WHERE status = 'Paid'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
        try {
            $massPaid = array_fill_keys(array_map('intval', $this->whmcs->query("SELECT relid FROM tblinvoiceitems WHERE type = 'Invoice'")->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (PDOException) {
            $massPaid = [];
        }
        $moneyless = [];
        foreach ($invoices as $invoice) {
            $id = (int) $invoice['id'];
            $credit = (int) round((float) $invoice['credit'] * 100);
            if ((int) round((float) $invoice['total'] * 100) + $credit <= 0 || ($credit <= 0 && !isset($paidOn[$id]) && !isset($massPaid[$id]))) {
                $moneyless[$id] = true;
            }
        }

        return $moneyless;
    }

    /** Upgraded or downgraded in WHMCS on or after $from (the day its last paid line was billed), or on a day not kept. */
    private function upgradedSince(Service $service, string $from): bool
    {
        if ($service->whmcs_addon_id !== null || !array_key_exists((int) $service->id, $this->upgraded)) {
            return false;
        }
        $day = $this->upgraded[(int) $service->id];

        return $day === null || $day >= $from;
    }

    private function name(string $reason, Service $service, string $detail = ''): void
    {
        $this->named[$reason][(int) $service->id] ??= $detail;
    }

    /** @param  array<int, array{id: int, invoice: int, paid: bool, at: string, billed: string|null, due: string|null, other: bool}>  $lines */
    private function service(Service $service, array $lines): void
    {
        $due = CarbonImmutable::parse($service->expires_at)->startOfDay();

        $paid = array_values(array_filter($lines, fn (array $line) => $line['paid']));
        // The latest period paid; of two lines for the same one, the later line.
        usort($paid, fn (array $a, array $b) => [$b['due'] ?? '', $b['id']] <=> [$a['due'] ?? '', $a['id']]);
        $last = $paid[0] ?? null;
        if ($last === null) {
            $this->name('no_paid', $service);
        } elseif ($last['due'] === null) {
            $this->name('no_due', $service, "line #{$last['id']}");
        } elseif (isset($this->moneyless[$last['invoice']])) {
            $this->name('no_money', $service, "invoice #{$last['invoice']}");
        } elseif ($this->upgradedSince($service, min($last['billed'] ?? $last['due'], $last['due']))) {
            $this->name('upgrade', $service, "line #{$last['id']} billed " . ($last['billed'] ?? $last['due']));
        } else {
            $start = CarbonImmutable::parse($last['due'])->startOfDay();
            $end = $service->periodEnd($start);
            // Its order's own line on a pro rata product: the first period this app gives an order of that day.
            $first = count($paid) === 1 && $service->created_at !== null && $start->toDateString() === CarbonImmutable::parse($service->created_at)->toDateString()
                && $service->parent_service_id === null && $service->product !== null && $service->plan !== null
                ? OrderPricing::firstPeriod($service->product, $service->plan, $start) : null;
            if ($end !== null && $end->equalTo($due)) {
                $this->write($last['id'], $start, $due, $last['at']);
                $this->counts['paid']++;
            } elseif ($first !== null && $first['end']->equalTo($due)) {
                $this->write($last['id'], $start, $due, $last['at']);
                $this->counts['prorata']++;
            } else {
                $this->name('dates', $service, "line #{$last['id']} due {$last['due']}, one cycle on " . ($end?->toDateString() ?? 'unknown')
                    . ($first !== null ? ", its first pro rata period to {$first['end']->toDateString()}" : '') . ", due date {$due->toDateString()}");
            }
        }

        $unpaid = array_values(array_filter($lines, fn (array $line) => !$line['paid']));
        $open = array_values(array_filter($unpaid, fn (array $line) => $line['due'] === $due->toDateString()));
        if ($open === []) {
            return; // an unpaid line elsewhere stays as it is
        }
        $others = array_filter($unpaid, fn (array $line) => $line['other']);
        if ($others !== []) {
            // Before the count: two unpaid lines left with the first customer are that customer's, not two renewals to sort (REV-804-5).
            $this->name('other_client', $service, implode(', ', array_map(fn (array $line) => "line #{$line['id']} of invoice #{$line['invoice']}", $others)));
        } elseif (count($unpaid) > 1) {
            $this->name('unpaid', $service, count($unpaid) . ' unpaid lines');
        } elseif (($end = $service->periodEnd($due)) !== null) {
            // After the paid line is written: a month-end day goes by the period before it, as the cron's will.
            $this->write($open[0]['id'], $due, $end);
            $this->counts['open']++;
        }
    }

    /** Around the models, as the importer writes its rows: no observer has a say on an invoice WHMCS closed. */
    private function write(int $line, CarbonImmutable $start, CarbonImmutable $end, ?string $appliedAt = null): void
    {
        DB::table('invoice_items')->where('id', $line)->update(['period_start' => $start->toDateString(), 'period_end' => $end->toDateString()]
            + ($appliedAt === null ? [] : ['applied_at' => $appliedAt]));
    }

    private static function day(mixed $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}

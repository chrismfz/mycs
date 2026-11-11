<?php

namespace App\Services\Import;

use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Throwable;

/**
 * The period of the two WHMCS lines every rule here asks about (owner 2026-11-11, sweep 8): a line imported from WHMCS
 * carries no period, and whoever asks «which period did this line pay?» has to guess. For each LIVE service (active or
 * suspended) on a recurring plan with a due date, from WHMCS's own `tblinvoiceitems.duedate` (the start of the period
 * the line bills):
 *  - its last paid line (the latest duedate among its lines on paid invoices) takes `duedate .. due date` and is marked
 *    applied as of the day its invoice was paid, only when one cycle on from the duedate IS the service's due date, by
 *    this app's own arithmetic (Service::periodEnd). A pro rata product's first order, its only paid line and due the day
 *    the service was made, takes `that day .. due date` (what the customer paid for);
 *  - its open renewal (the one line on an unpaid invoice due ON the service's due date) takes `due date .. one cycle
 *    on`, not applied.
 * Anything else stays as it came (no period, as before) and the service is named for staff. An addon imported as a
 * service of its own (WhmcsAddonImport, its «Addon» lines) goes by the same rule, without the pro rata case. Domains
 * have their own (WhmcsDomainImport).
 *
 * After the lines are in: the rows are read here, so only what was imported is ever written, and the open renewal's end
 * sees the paid line before it, as the cron will.
 */
final class WhmcsLinePeriods
{
    /** @var array<int, string> service => why a line of it got no period */
    private array $named = [];

    /** @var array{paid: int, prorata: int, open: int} */
    private array $counts = ['paid' => 0, 'prorata' => 0, 'open' => 0];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array{paid: int, prorata: int, open: int, services: int}, named: array<int, string>, notes: list<string>} */
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
        } catch (PDOException $e) {
            // Not a WHMCS this importer knows (every one keeps the column): nothing written, and said.
            return ['notes' => ["The lines' due dates (tblinvoiceitems.duedate) could not be read: no invoice line was given its period ({$e->getMessage()})."]] + $none;
        }
        foreach ($services as $id => $service) {
            $this->service($service, array_map(fn (object $line) => ['id' => (int) $line->id, 'paid' => $line->status === Invoice::STATUS_PAID,
                'at' => (string) ($line->paid_at ?? $line->created_at ?? now()->toDateTimeString()), 'due' => $due[(int) $line->id] ?? null], $lines[$id] ?? []));
        }
        ksort($this->named);

        return ['counts' => $this->counts + ['services' => $services->count()], 'named' => $this->named, 'notes' => []];
    }

    /**
     * The lines that bill a period of these services (no kind), on paid and unpaid invoices. With or without a period
     * already: run again on the same import, the same two lines are found and written the same.
     *
     * @param  list<int>  $services
     * @return array<int, array<int, object>> service => line id => the line with its invoice's status and dates
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
                ->get(['invoice_items.id', 'invoice_items.reference_id', 'invoices.status', 'invoices.paid_at', 'invoices.created_at']);
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

    /** @param  array<int, array{id: int, paid: bool, at: string, due: string|null}>  $lines */
    private function service(Service $service, array $lines): void
    {
        $due = CarbonImmutable::parse($service->expires_at)->startOfDay();
        $why = [];

        $paid = array_values(array_filter($lines, fn (array $line) => $line['paid']));
        // The latest period paid; of two lines for the same one, the later line.
        usort($paid, fn (array $a, array $b) => [$b['due'] ?? '', $b['id']] <=> [$a['due'] ?? '', $a['id']]);
        $last = $paid[0] ?? null;
        if ($last === null) {
            $why[] = 'no paid line';
        } elseif ($last['due'] === null) {
            $why[] = "its last paid line (#{$last['id']}) has no due date";
        } else {
            $start = CarbonImmutable::parse($last['due'])->startOfDay();
            $end = $service->periodEnd($start);
            $firstOrder = count($paid) === 1 && $service->created_at !== null && $start->toDateString() === CarbonImmutable::parse($service->created_at)->toDateString()
                && $service->parent_service_id === null && $service->product?->proratesPlan($service->plan) === true && $start->lessThan($due);
            if ($end !== null && $end->equalTo($due)) {
                $this->write($last['id'], $start, $due, $last['at']);
                $this->counts['paid']++;
            } elseif ($firstOrder) {
                $this->write($last['id'], $start, $due, $last['at']);
                $this->counts['prorata']++;
            } else {
                $why[] = "its last paid line (#{$last['id']}) is due {$last['due']}, one cycle on is " . ($end?->toDateString() ?? 'unknown')
                    . ", its due date {$due->toDateString()}";
            }
        }

        $open = array_values(array_filter($lines, fn (array $line) => !$line['paid'] && $line['due'] === $due->toDateString()));
        if (count($open) === 1) {
            // After the paid line is written: a month-end day goes by the period before it, as the cron's will.
            $end = $service->periodEnd($due);
            if ($end !== null) {
                $this->write($open[0]['id'], $due, $end);
                $this->counts['open']++;
            }
        } elseif (count($open) > 1) {
            $why[] = count($open) . " unpaid lines due on its due date {$due->toDateString()}";
        }

        if ($why !== []) {
            $this->named[(int) $service->id] = implode('; ', $why);
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

<?php

namespace App\Services\Import;

use App\Models\Domain;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Billing\RenewalPrice;
use App\Services\Order\OrderPricing;
use App\Support\DunningWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PDO;
use PDOException;

/**
 * What each live service and domain is going to do, in WHMCS and in this instance, side by side (ROADMAP 9β, 1): its cycle,
 * its next renewal amount, its next due date, the day its renewal is invoiced, whether a saved method charges it, the day it
 * is suspended and the day it is terminated, whether a hold keeps it. Read only on both. A difference is a customer who is
 * billed, charged or suspended otherwise than before the move.
 *
 * The one thing it must never do is say «same» for what will not be (adversarial review of #565): each date is the day
 * **the cron acts**, so «never» where it will not (the switch off, the client exempt, a hold, a hand suspension that the
 * cron never terminates) and the dunning window's next allowed day where it waits.
 *
 * - The days are WHMCS's rules and this app's, each as its cron applies it (CronJob: `expires_at < today + invoice days`,
 *   `date(expires_at) < today − suspend days`, `… − terminate days`). WHMCS's: the renewal is invoiced on `nextinvoicedate`
 *   less the invoice days, and suspended and terminated on the due date plus the days: its activity log shows 74 suspensions
 *   exactly 8 days after the invoice's due date and 2 terminations 60 days after, so the day «after» is the same day in
 *   WHMCS and the day after here.
 * - A service that is free, one-time or pending has no renewal to forecast: only its status and cycle are compared. One that
 *   is ended in WHMCS (terminated, cancelled, fraud) is compared by status alone: it must not be live here.
 * - A tax type different in the two systems is a difference of its own: amounts are then not comparable.
 */
final class WhmcsForecastParity
{
    /** WHMCS's cycle names => [type, period, unit]. */
    private const CYCLES = [
        'monthly' => ['recurring', 1, 'month'], 'quarterly' => ['recurring', 3, 'month'], 'semi-annually' => ['recurring', 6, 'month'],
        'annually' => ['recurring', 1, 'year'], 'biennially' => ['recurring', 2, 'year'], 'triennially' => ['recurring', 3, 'year'],
        'free account' => ['free', null, null], 'free' => ['free', null, null], 'one time' => ['one-time', null, null],
    ];

    private const NEVER = 'never';

    /** @var array<string, array{total: int, differ: int}> */
    private array $summary = [];

    /** @var list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}> */
    private array $differences = [];

    /** @var array<string, string> WHMCS's automation settings */
    private array $config = [];

    /** @var array<string, true> client id|gateway => has a saved method in WHMCS */
    private array $methods = [];

    /** @var array<int, true> clients whose group WHMCS never suspends nor terminates */
    private array $exempt = [];

    private CarbonImmutable $today;

    public function __construct(private readonly PDO $whmcs)
    {
        $this->today = CarbonImmutable::today();
    }

    /** @return array{summary: array<string, array{total: int, differ: int}>, differences: list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}>} */
    public function run(): array
    {
        $this->load();
        $this->settings();
        $this->services();
        $this->endedServices();
        $this->domains();

        return ['summary' => $this->summary, 'differences' => $this->differences];
    }

    private function load(): void
    {
        foreach ($this->rows("SELECT setting, value FROM tblconfiguration WHERE setting IN ('AutoSuspension', 'AutoSuspensionDays', 'AutoTermination', 'AutoTerminationDays', 'CreateInvoiceDaysBefore',
            'CreateDomainInvoiceDaysBefore', 'CreateInvoiceDaysBeforeMonthly', 'CreateInvoiceDaysBeforeQuarterly', 'CreateInvoiceDaysBeforeSemiAnnually', 'CreateInvoiceDaysBeforeAnnually',
            'CreateInvoiceDaysBeforeBiennially', 'CreateInvoiceDaysBeforeTriennially', 'TaxType')") as $row) {
            $this->config[$row['setting']] = trim((string) $row['value']);
        }
        foreach ($this->rows('SELECT userid, gateway_name FROM tblpaymethods WHERE deleted_at IS NULL') as $row) {
            $this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['gateway_name'])] = true;
        }
        foreach ($this->rows('SELECT c.id, g.susptermexempt FROM tblclients c JOIN tblclientgroups g ON g.id = c.groupid') as $row) {
            if (in_array(strtolower(trim((string) $row['susptermexempt'])), ['on', '1', 'yes'], true)) {
                $this->exempt[(int) $row['id']] = true;
            }
        }
    }

    /** Amounts are only comparable when both systems read prices the same way. */
    private function settings(): void
    {
        $theirs = strtolower($this->config['TaxType'] ?? '');
        if ($theirs !== '') {
            $this->compare('settings', 0, 'tax type', $theirs, strtolower((string) config('settings.tax_type', 'exclusive')));
        }
    }

    private function services(): void
    {
        $suspendDays = (int) ($this->config['AutoSuspensionDays'] ?? 0);
        $terminateDays = (int) ($this->config['AutoTerminationDays'] ?? 0);
        $suspendOn = $this->on($this->config['AutoSuspension'] ?? '');
        $terminateOn = $this->on($this->config['AutoTermination'] ?? '');
        $ourSuspend = (int) config('settings.cronjob_order_suspend', 8);
        $ourTerminate = (int) config('settings.cronjob_order_terminate', 60);
        $ourInvoice = (int) config('settings.cronjob_invoice', 7);
        $statement = $this->hostingRows("domainstatus IN ('Active', 'Suspended', 'Pending')");
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $service = Service::query()->with(['plan', 'client.group'])->find($id);
            if ($service === null) {
                $this->compare('service', $id, 'exists', 'yes', 'no');

                continue;
            }
            $this->compare('service', $id, 'status', $this->statusWord((string) $row['domainstatus']), (string) $service->status);
            $cycle = self::CYCLES[strtolower(trim((string) $row['billingcycle']))] ?? null;
            $plan = $service->plan;
            // A free account has no plan here and no due date: never billed (BIL-6), which is what free means.
            $ours = $plan === null ? ($service->expires_at === null ? 'free' : 'none') : ($plan->type === 'recurring' ? "recurring {$plan->billing_period} {$plan->billing_unit}" : (string) $plan->type);
            $theirs = $cycle === null ? 'unknown «' . $row['billingcycle'] . '»' : ($cycle[0] === 'recurring' ? "recurring {$cycle[1]} {$cycle[2]}" : $cycle[0]);
            $this->compare('service', $id, 'cycle', $theirs, $ours);
            if ($cycle === null || $cycle[0] !== 'recurring' || strtolower((string) $row['domainstatus']) === 'pending' || $service->expires_at === null) {
                continue;
            }
            $due = $this->date($row['nextduedate']);
            $expires = CarbonImmutable::parse($service->expires_at);
            // The line is its price times its quantity, on both sides.
            $theirQty = max(1, (int) ($row['qty'] ?? 1));
            $this->compare('service', $id, 'renewal amount', number_format((float) $row['amount'] * $theirQty, 2, '.', ''),
                number_format((float) RenewalPrice::for($service, $expires, true)->price * max(1, (int) $service->quantity), 2, '.', ''));
            $this->compare('service', $id, 'next due date', $due, $expires->toDateString());
            // WHMCS invoices a renewal when its nextinvoicedate is within «invoice days» (the cycle's own, else the general): 7 days
            // before the due date in 7,851 of its invoices.
            $this->compare('service', $id, 'invoiced on', $this->shift($row['nextinvoicedate'], -$this->invoiceDays((string) $row['billingcycle'])), $expires->subDays($ourInvoice)->toDateString());
            // Holds: «always» and a date still to come hold; a date past does not.
            $theirHold = $this->theirHold($row);
            $ourHold = $this->ourHold($service);
            $this->compare('service', $id, 'suspension hold', $theirHold, $ourHold);
            // Suspended on / terminated on: the day the cron acts, «never» where it will not.
            $theirExempt = isset($this->exempt[(int) $row['userid']]);
            $theirHeld = $theirHold !== 'none';
            $this->compare('service', $id, 'suspended on', $suspendOn && !$theirHeld && !$theirExempt && $due !== '' ? $this->plus($due, $suspendDays) : self::NEVER,
                $this->ourDay($service, (bool) config('settings.cronjob_suspend_enabled', true), $ourHold, $expires->addDays($ourSuspend + 1)->toDateString(), false));
            $this->compare('service', $id, 'terminated on', $terminateOn && !$theirHeld && !$theirExempt && $due !== '' ? $this->plus($due, $terminateDays) : self::NEVER,
                $this->ourDay($service, (bool) config('settings.cronjob_terminate_enabled', true), $ourHold, $expires->addDays($ourTerminate + 1)->toDateString(), true));
            $theirCharge = empty($row['subscriptionid']) && isset($this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['paymentmethod'])]);
            $this->compare('service', $id, 'charged by a saved method', $theirCharge ? 'yes' : 'no', $this->chargedHere($service) ? 'yes' : 'no');
        }
    }

    /** What WHMCS has ended must not be live here: nothing to bill or suspend. */
    private function endedServices(): void
    {
        $statement = $this->hostingRows("domainstatus NOT IN ('Active', 'Suspended', 'Pending')");
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $service = Service::query()->find((int) $row['id']);
            if ($service !== null) {
                $this->compare('service', (int) $row['id'], 'status', $this->statusWord((string) $row['domainstatus']), (string) $service->status);
            }
        }
    }

    private function domains(): void
    {
        if (!$this->tableExists('tbldomains')) {
            return;
        }
        $billing = (bool) config('settings.domain_billing_enabled', true);
        $ourDays = RenewalBilling::domainInvoiceDays();
        $statement = $this->whmcs->query('SELECT id, userid, status, expirydate, nextduedate, nextinvoicedate, recurringamount, donotrenew, paymentmethod, subscriptionid FROM tbldomains ORDER BY id');
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $domain = Domain::query()->with('client')->find($id);
            $live = in_array((string) $row['status'], ['Active', 'Expired', 'Grace', 'Redemption'], true);
            if ($domain === null) {
                if ($live) {
                    $this->compare('domain', $id, 'exists', 'yes', 'no');
                }

                continue;
            }
            $this->compare('domain', $id, 'status', strtolower(str_replace(' ', '_', (string) $row['status'])), $domain->status->value);
            if (!$live) {
                continue;
            }
            $this->compare('domain', $id, 'expires on', $this->date($row['expirydate']), $domain->expires_at?->toDateString() ?? '');
            $this->compare('domain', $id, 'renewal amount', number_format((float) $row['recurringamount'], 2, '.', ''), $this->domainLine($domain));
            $this->compare('domain', $id, 'renews automatically', (int) $row['donotrenew'] === 1 ? 'no' : 'yes', $domain->auto_renew ? 'yes' : 'no');
            if ((int) $row['donotrenew'] !== 1 && $domain->expires_at !== null) {
                $this->compare('domain', $id, 'invoiced on', $this->shift($row['nextinvoicedate'], -$this->invoiceDays('', true)),
                    $billing && $domain->auto_renew ? CarbonImmutable::parse($domain->expires_at)->subDays($ourDays)->toDateString() : self::NEVER);
            }
            $theirCharge = empty($row['subscriptionid']) && isset($this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['paymentmethod'])]);
            $this->compare('domain', $id, 'charged by a saved method', $theirCharge ? 'yes' : 'no', $this->chargedHere($domain) ? 'yes' : 'no');
        }
    }

    /** What the renewal invoice will carry for a domain: its price for its next period, net of tax (billDomainRenewal). */
    private function domainLine(Domain $domain): string
    {
        $price = $domain->renewalPriceFor($domain->nextRenewalYears());

        return $price === null ? 'not billed' : number_format((float) OrderPricing::toNet($price, $domain->client), 2, '.', '');
    }

    /**
     * The day the cron suspends (or terminates) a service here, if nothing stops it: «never» with the switch off, for a client
     * exempt (itself or its group), under a hold, and (termination) for a service suspended by hand, which has no
     * `dunning_suspended_at` (terminable()); else the day in the dunning window it waits for.
     */
    private function ourDay(Service $service, bool $enabled, string $hold, string $day, bool $terminate): string
    {
        $exempt = $service->client !== null && ($service->client->dunning_exempt || (bool) $service->client->group?->dunning_exempt);
        if (!$enabled || $exempt || $hold !== 'none' || ($terminate && $service->status === Service::STATUS_SUSPENDED && $service->dunning_suspended_at === null)) {
            return self::NEVER;
        }

        return DunningWindow::firstDayFrom(Carbon::parse($day))->toDateString();
    }

    private function theirHold(array $row): string
    {
        if ((int) $row['overideautosuspend'] !== 1) {
            return 'none';
        }
        $until = $this->date($row['overidesuspenduntil']);

        return $until === '' ? 'always' : ($until < $this->today->toDateString() ? 'none' : $until);
    }

    private function ourHold(Service $service): string
    {
        if ($service->suspend_hold_until === null) {
            return 'none';
        }
        $until = $service->suspend_hold_until->toDateString();

        return $until >= '9999-01-01' ? 'always' : ($until < $this->today->toDateString() ? 'none' : $until);
    }

    /**
     * Whether a saved method charges the item here, as `ItemPaymentMethod::chargedBy` decides it, **as if its gateway were
     * enabled**: the gateways of an import come disabled, and the cut-over enables them.
     */
    private function chargedHere(Service|Domain $item): bool
    {
        $agreement = $item->client?->autoChargeMethod();
        $own = $item->payment_gateway_id === null || $item->paymentGateway === null ? null : (int) $item->payment_gateway_id;

        return $agreement !== null && ($own === null || $own === (int) $agreement->gateway_id) && empty($item->subscription_id);
    }

    /** WHMCS's «days before»: a domain's own, or the cycle's own, else the general. */
    private function invoiceDays(string $cycle, bool $domain = false): int
    {
        $names = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi-annually' => 'SemiAnnually', 'annually' => 'Annually', 'biennially' => 'Biennially', 'triennially' => 'Triennially'];
        $own = $domain ? ($this->config['CreateDomainInvoiceDaysBefore'] ?? '') : (isset($names[strtolower(trim($cycle))]) ? ($this->config['CreateInvoiceDaysBefore' . $names[strtolower(trim($cycle))]] ?? '') : '');

        return ctype_digit($own) ? (int) $own : (int) ($this->config['CreateInvoiceDaysBefore'] ?? 0);
    }

    /** WHMCS's status word as this app writes it (a fraud order is cancelled here). */
    private function statusWord(string $status): string
    {
        $status = strtolower(trim($status));

        return $status === 'fraud' ? 'cancelled' : $status;
    }

    /** A date moved by days (negative: back), or '' when it has none or is not a date. */
    private function shift(mixed $value, int $days): string
    {
        return $this->plus($this->date($value), $days);
    }

    private function plus(string $date, int $days): string
    {
        if ($date === '') {
            return '';
        }
        // A real date only: «2026-13-45» would roll over into another year.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "invalid «{$date}»";
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date)->addDays($days)->toDateString();
    }

    /** A WHMCS date (0000-00-00 and empty are none). */
    private function date(mixed $value): string
    {
        $value = substr(trim((string) $value), 0, 10);

        return $value === '' || str_starts_with($value, '0000') ? '' : $value;
    }

    private function on(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['on', '1', 'yes'], true);
    }

    /** The services WHMCS holds in a state, with their quantity where the table has one. */
    private function hostingRows(string $where): \PDOStatement
    {
        $columns = 'id, userid, domainstatus, billingcycle, amount, nextduedate, nextinvoicedate, paymentmethod, subscriptionid, overideautosuspend, overidesuspenduntil';
        try {
            return $this->whmcs->query("SELECT {$columns}, qty FROM tblhosting WHERE {$where} ORDER BY id");
        } catch (PDOException) {
            return $this->whmcs->query("SELECT {$columns} FROM tblhosting WHERE {$where} ORDER BY id");
        }
    }

    private function compare(string $kind, int $id, string $field, string $whmcs, string $mycs): void
    {
        $key = "{$kind} · {$field}";
        $this->summary[$key] ??= ['total' => 0, 'differ' => 0];
        $this->summary[$key]['total']++;
        if ($whmcs !== $mycs) {
            $this->summary[$key]['differ']++;
            $this->differences[] = ['kind' => $kind, 'id' => $id, 'field' => $field, 'whmcs' => $whmcs, 'mycs' => $mycs];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
    }
}

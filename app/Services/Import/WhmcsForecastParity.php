<?php

namespace App\Services\Import;

use App\Models\Domain;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Billing\RenewalPrice;
use Carbon\CarbonImmutable;
use PDO;
use PDOException;

/**
 * What each live service and domain is going to do, in WHMCS and in this instance, side by side (ROADMAP 9β, 1): its cycle,
 * its next renewal amount, its next due date, the day its renewal is invoiced, whether a saved method charges it, the day it
 * is suspended and the day it is terminated, whether a hold keeps it. Read only on both. A difference is a customer who is
 * billed, charged or suspended otherwise than before the move.
 *
 * - The days are WHMCS's rules and this app's, each as its cron applies it (CronJob: `expires_at < today + invoice days`,
 *   `date(expires_at) < today − suspend days`, `… − terminate days`). WHMCS's: the renewal is invoiced on `nextinvoicedate`,
 *   and suspended and terminated on the due date plus the days: its activity log shows 74 suspensions exactly 8 days after the
 *   invoice's due date and 2 terminations 60 days after, so the day «after» is the same day in WHMCS and the day after here.
 * - A service that is free, one-time, pending or ended has no renewal to forecast: only its status and cycle are compared.
 */
final class WhmcsForecastParity
{
    /** WHMCS's cycle names => [type, period, unit]. */
    private const CYCLES = [
        'monthly' => ['recurring', 1, 'month'], 'quarterly' => ['recurring', 3, 'month'], 'semi-annually' => ['recurring', 6, 'month'],
        'annually' => ['recurring', 1, 'year'], 'biennially' => ['recurring', 2, 'year'], 'triennially' => ['recurring', 3, 'year'],
        'free account' => ['free', null, null], 'free' => ['free', null, null], 'one time' => ['one-time', null, null],
    ];

    /** @var array<string, array{total: int, differ: int}> */
    private array $summary = [];

    /** @var list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}> */
    private array $differences = [];

    /** @var array<string, string> WHMCS's automation settings */
    private array $config = [];

    /** @var array<string, true> client id|gateway => has a saved method in WHMCS */
    private array $methods = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{summary: array<string, array{total: int, differ: int}>, differences: list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}>} */
    public function run(): array
    {
        $this->load();
        $this->services();
        $this->domains();

        return ['summary' => $this->summary, 'differences' => $this->differences];
    }

    private function load(): void
    {
        foreach ($this->rows("SELECT setting, value FROM tblconfiguration WHERE setting IN ('AutoSuspension', 'AutoSuspensionDays', 'AutoTermination', 'AutoTerminationDays', 'CreateInvoiceDaysBefore', 'CreateDomainInvoiceDaysBefore', 'CreateInvoiceDaysBeforeMonthly', 'CreateInvoiceDaysBeforeQuarterly', 'CreateInvoiceDaysBeforeSemiAnnually', 'CreateInvoiceDaysBeforeAnnually', 'CreateInvoiceDaysBeforeBiennially', 'CreateInvoiceDaysBeforeTriennially')") as $row) {
            $this->config[$row['setting']] = trim((string) $row['value']);
        }
        foreach ($this->rows('SELECT userid, gateway_name FROM tblpaymethods WHERE deleted_at IS NULL') as $row) {
            $this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['gateway_name'])] = true;
        }
    }

    private function services(): void
    {
        $suspendDays = (int) ($this->config['AutoSuspensionDays'] ?? 0);
        $terminateDays = (int) ($this->config['AutoTerminationDays'] ?? 0);
        $suspendOn = in_array(strtolower($this->config['AutoSuspension'] ?? ''), ['on', '1'], true);
        $terminateOn = in_array(strtolower($this->config['AutoTermination'] ?? ''), ['on', '1'], true);
        $ourSuspend = (int) config('settings.cronjob_order_suspend', 8);
        $ourTerminate = (int) config('settings.cronjob_order_terminate', 60);
        $ourInvoice = (int) config('settings.cronjob_invoice', 7);
        $statement = $this->whmcs->query("SELECT id, userid, domainstatus, billingcycle, amount, nextduedate, nextinvoicedate, paymentmethod, subscriptionid,
            overideautosuspend, overidesuspenduntil FROM tblhosting WHERE domainstatus IN ('Active', 'Suspended', 'Pending') ORDER BY id");
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $service = Service::query()->with(['plan', 'client'])->find($id);
            if ($service === null) {
                $this->differ('service', $id, 'exists', 'yes', 'no');

                continue;
            }
            $this->compare('service', $id, 'status', strtolower((string) $row['domainstatus']), (string) $service->status);
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
            $this->compare('service', $id, 'renewal amount', number_format((float) $row['amount'], 2, '.', ''),
                number_format((float) RenewalPrice::for($service, $expires, true)->price, 2, '.', ''));
            $this->compare('service', $id, 'next due date', $due, $expires->toDateString());
            // WHMCS invoices a renewal when its nextinvoicedate is within «invoice days» (the cycle's own, else the general): 7 days
            // before the due date in 7,851 of its invoices.
            $this->compare('service', $id, 'invoiced on', $this->shift($row['nextinvoicedate'], $this->invoiceDays((string) $row['billingcycle'])), $expires->subDays($ourInvoice)->toDateString());
            // Suspended on / terminated on: the day the cron acts, if nothing holds it back.
            $held = (int) $row['overideautosuspend'] === 1;
            $theirHold = $held ? ($this->date($row['overidesuspenduntil']) ?: 'always') : 'none';
            $ourHold = $service->suspend_hold_until === null ? 'none' : $service->suspend_hold_until->toDateString();
            $this->compare('service', $id, 'suspension hold', $theirHold, $ourHold);
            $this->compare('service', $id, 'suspended on', $suspendOn && !$held ? CarbonImmutable::parse($due)->addDays($suspendDays)->toDateString() : 'never',
                $expires->addDays($ourSuspend + 1)->toDateString());
            $this->compare('service', $id, 'terminated on', $terminateOn && !$held ? CarbonImmutable::parse($due)->addDays($terminateDays)->toDateString() : 'never',
                $expires->addDays($ourTerminate + 1)->toDateString());
            $theirCharge = empty($row['subscriptionid']) && isset($this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['paymentmethod'])]);
            $this->compare('service', $id, 'charged by a saved method', $theirCharge ? 'yes' : 'no', $this->chargedHere($service) ? 'yes' : 'no');
        }
    }

    private function domains(): void
    {
        $statement = $this->tableExists('tbldomains') ? $this->whmcs->query("SELECT id, userid, status, expirydate, nextduedate, nextinvoicedate, recurringamount, donotrenew, paymentmethod, subscriptionid
            FROM tbldomains WHERE status IN ('Active', 'Expired', 'Grace', 'Redemption') ORDER BY id") : null;
        $ourDays = RenewalBilling::domainInvoiceDays();
        while ($statement && ($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $id = (int) $row['id'];
            $domain = Domain::query()->with('client')->find($id);
            if ($domain === null) {
                $this->differ('domain', $id, 'exists', 'yes', 'no');

                continue;
            }
            $this->compare('domain', $id, 'status', strtolower(str_replace(' ', '_', (string) $row['status'])), $domain->status->value);
            $this->compare('domain', $id, 'expires on', $this->date($row['expirydate']), $domain->expires_at?->toDateString() ?? '');
            $this->compare('domain', $id, 'renewal amount', number_format((float) $row['recurringamount'], 2, '.', ''), number_format((float) $domain->recurring_amount, 2, '.', ''));
            $this->compare('domain', $id, 'renews automatically', (int) $row['donotrenew'] === 1 ? 'no' : 'yes', $domain->auto_renew ? 'yes' : 'no');
            if ((int) $row['donotrenew'] !== 1 && $domain->expires_at !== null) {
                $this->compare('domain', $id, 'invoiced on', $this->shift($row['nextinvoicedate'], $this->invoiceDays('', true)), CarbonImmutable::parse($domain->expires_at)->subDays($ourDays)->toDateString());
            }
            $theirCharge = empty($row['subscriptionid']) && isset($this->methods[(int) $row['userid'] . '|' . strtolower((string) $row['paymentmethod'])]);
            $this->compare('domain', $id, 'charged by a saved method', $theirCharge ? 'yes' : 'no', $this->chargedHere($domain) ? 'yes' : 'no');
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

    private function differ(string $kind, int $id, string $field, string $whmcs, string $mycs): void
    {
        $this->compare($kind, $id, $field, $whmcs, $mycs);
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

    /** A WHMCS date moved back by days, or '' when it has none. */
    private function shift(mixed $value, int $days): string
    {
        $date = $this->date($value);

        return $date === '' ? '' : CarbonImmutable::parse($date)->subDays($days)->toDateString();
    }

    /** A WHMCS date (0000-00-00 and empty are none). */
    private function date(mixed $value): string
    {
        $value = substr(trim((string) $value), 0, 10);

        return $value === '' || str_starts_with($value, '0000') ? '' : $value;
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

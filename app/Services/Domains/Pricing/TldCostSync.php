<?php

namespace App\Services\Domains\Pricing;

use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarNotConfigured;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The registrar's cost, onto a TLD's price rows (docs/domains.md D-6): ONLY `cost` and `cost_currency`, never the price
 * a customer pays (that moves only with the import or by hand). A register or renew row of N years costs N times the
 * yearly cost; transfer, grace and redemption are one-off. Rows that do not exist are not created: the import makes
 * them. With them the TLD's grace and redemption days, as the registrar reports them (D5a; 0 = no such stage).
 */
class TldCostSync
{
    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /** The TLD's connection, if its costs can be read from it: active, not manual, a registrar with a price list. */
    public function connection(DomainTld $tld): ?DomainRegistrarConnection
    {
        $connection = $tld->registrarConnection;
        if ($connection === null || !$connection->isUsable() || !$this->factory->for($connection)->capabilities()->supportsPricingSync) {
            return null;
        }

        return $connection;
    }

    /**
     * @return int rows changed: price rows whose cost moved, and the TLD when its days did
     *
     * @throws RuntimeException the registrar's own error, or no cost at all
     */
    public function sync(DomainTld $tld): int
    {
        $connection = $this->connection($tld)
            ?? throw new DomainRegistrarNotConfigured("The TLD .{$tld->tld} has no registrar its costs can be read from.");
        $pricing = $this->factory->for($connection)->getTldPricing($tld->tld, $this->factory->credentialsFor($connection));
        if ($pricing->costs === []) {
            throw new RuntimeException("The registrar quoted no cost for .{$tld->tld}.");
        }

        return DB::transaction(function () use ($tld, $pricing) {
            $changed = 0;
            // The registry's stages after expiry, as the registrar reports them (D5a, D-8): its truth, as the costs.
            $tld->fill(array_filter(['grace_days' => $pricing->graceDays, 'redemption_days' => $pricing->redemptionDays], fn ($days) => $days !== null));
            if ($tld->isDirty(['grace_days', 'redemption_days'])) {
                $tld->save(); // audited
                $changed++;
            }
            foreach ($pricing->costs as $operation => $entry) {
                if (!TldPriceMath::isAmount($entry['cost'])) {
                    continue; // a negative or garbled cost is never written
                }
                $perYear = in_array($operation, ['register', 'renew'], true);
                // A cost quoted for 2 years (.gr) is no cost of a 1-year price (which the registrar does not sell).
                $from = $perYear ? $pricing->minYears : 1;
                foreach ($tld->prices()->where('operation', $operation)->whereBetween('years', [$from, 10])->get() as $row) {
                    $cost = (string) $pricing->costFor($operation, $perYear ? $row->years : 1);
                    $row->fill(['cost' => $cost, 'cost_currency' => $entry['currency']]);
                    if ($row->isDirty(['cost', 'cost_currency'])) {
                        $row->save();
                        $changed++;
                    }
                }
            }

            return $changed;
        });
    }
}

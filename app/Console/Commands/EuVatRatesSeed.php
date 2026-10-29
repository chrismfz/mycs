<?php

namespace App\Console\Commands;

use App\Services\Tax\EuVatRates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use MyCS\EuVatRates\StandardRates;

/**
 * The EU member states' standard VAT rates into Tax Rates (docs/eu-vat.md), only the countries with none: what staff
 * wrote stays. Run again, it adds nothing. From `mycs:install` on request, or by hand.
 */
class EuVatRatesSeed extends Command
{
    protected $signature = 'mycs:vat-rates:seed {--dry-run : Only show what would be added}';

    protected $description = 'Adds the EU member states\' standard VAT rates to Tax Rates, only for countries without one';

    public function handle(EuVatRates $rates): int
    {
        Config::set('audit.console', true); // a new tax rate is audited, as staff's are
        $added = $rates->seed((bool) $this->option('dry-run'));
        foreach ($added as $iso => $rate) {
            $this->line("{$iso}: {$rate}%");
        }
        $this->info(sprintf('%s %d rate(s), checked against TEDB on %s; %d countries already had one.',
            $this->option('dry-run') ? 'Would add' : 'Added', count($added), StandardRates::checkedOn(), count(StandardRates::EU) - count($added)));

        return self::SUCCESS;
    }
}

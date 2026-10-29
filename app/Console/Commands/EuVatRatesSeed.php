<?php

namespace App\Console\Commands;

use App\Classes\Settings;
use App\Models\TaxRate;
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
    protected $signature = 'mycs:vat-rates:seed {--dry-run : Only show what would be added}
        {--force : Add them also where Tax Rates already has rates (asked when interactive)}';

    protected $description = 'Adds the EU member states\' standard VAT rates to Tax Rates, only for countries without one';

    public function handle(EuVatRates $rates): int
    {
        Config::set('audit.console', true); // a new tax rate is audited, as staff's are
        $dryRun = (bool) $this->option('dry-run');
        // An instance that already bills (myip: GR and «All»): a country that paid «All», or the single rate's, now
        // pays its own, and the single rate no longer stands for the company's country. Only when asked (PR #524).
        if (!$dryRun && !$this->option('force') && TaxRate::query()->exists()) {
            $this->warn('Tax Rates already has rates: customers of the countries added pay their own rate from the next invoice, '
                . 'and a single rate no longer stands for the company\'s country (set «Company country», Settings → Tax).');
            if (!$this->input->isInteractive() || !$this->confirm('Add the missing EU rates anyway?', false)) {
                $this->line('Nothing added. --dry-run shows what would be; --force adds them.');

                return self::FAILURE;
            }
        }
        $added = $rates->seed($dryRun);
        foreach ($added as $iso => $rate) {
            $this->line("{$iso}: {$rate}%");
        }
        $this->info(sprintf('%s %d rate(s), checked against TEDB on %s; %d countries already had one.',
            $dryRun ? 'Would add' : 'Added', count($added), StandardRates::checkedOn(), count(StandardRates::EU) - count($added)));

        if ($rates->keptHomeCountry !== null) {
            $this->line("Company country set to {$rates->keptHomeCountry} (Settings → Tax): the only rate's country, which stood for it until now.");
        } elseif ($added !== [] && !$dryRun && Settings::configuredHomeCountry() === null) {
            // More than one rate now: the only rate no longer stands for the company's country (Settings::homeCountry, #524).
            $this->line('Set «Company country» (Settings → Tax) if it is not set: with more than one rate, a customer without a country pays «All» or the company country\'s rate.');
        }

        return self::SUCCESS;
    }
}

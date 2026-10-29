<?php

namespace App\Console\Commands;

use App\Services\Tax\EuVatRates;
use Illuminate\Console\Command;
use MyCS\EuVatRates\TedbUnavailable;

/**
 * Weekly (Settings → Tax, off by default): today's EU standard VAT rates from the European Commission's TEDB against
 * Tax Rates. Staff are told of a difference (mail, chat); nothing is changed (docs/eu-vat.md).
 */
class EuVatRatesCheck extends Command
{
    protected $signature = 'mycs:vat-rates:check';

    protected $description = 'Compares Tax Rates with the EU standard VAT rates in TEDB and tells staff of a difference (changes nothing)';

    public function handle(EuVatRates $rates): int
    {
        if (!EuVatRates::enabled()) {
            $this->line('The EU VAT rates check is off (Settings → Tax).');

            return self::SUCCESS;
        }
        try {
            $result = $rates->check();
        } catch (TedbUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($result['differences'] as $difference) {
            $this->warn("{$difference['country']}: {$difference['ours']}% here, {$difference['tedb']}% in TEDB");
        }
        $this->info(sprintf('%d rate(s) compared, %d different.', $result['compared'], count($result['differences'])));

        return self::SUCCESS;
    }
}

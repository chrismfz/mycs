<?php

namespace App\Services\Import;

use App\Models\Setting;
use App\Models\TaxRate;
use MyCS\EuVatRates\StandardRates;
use PDO;
use PDOException;

/**
 * WHMCS's tax (`tbltax`, and its tax settings) as Tax Rates, so the first invoice after the cut-over charges what the
 * last one in WHMCS did (owner, 2026-10-31). A rate may be out of date there (Estonia is 24% since 2025, WHMCS's list may
 * say 20%): it is kept as WHMCS charged it and marked with that rate (`whmcs_rate`), so `mycs:vat-rates:seed
 * --update-imported` can bring the ones nobody changed here to the checked list, and only those.
 *
 * - Level 1, a country, one rate per country (the first; a second is said). A state rule, a second level and a rule
 *   for «any country» other than a plain «All» are not carried, and said.
 * - Settings: Tax Enabled, Tax Type, EU VAT (WHMCS's EU exemption and validation), the EU home country, the own VAT
 *   number. «Company country» is left to the EU home country (docs/eu-vat.md). A setting WHMCS has not is left as it is.
 */
final class WhmcsTaxImport
{
    /** @var array<string, int> */
    private array $counts = ['rates' => 0, 'skipped' => 0, 'settings' => 0];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(): array
    {
        $this->rates();
        $this->settings();

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    private function rates(): void
    {
        $seen = [];
        foreach ($this->rows('SELECT id, level, name, state, country, taxrate FROM tbltax ORDER BY level, id') as $row) {
            $country = strtoupper(trim((string) $row['country']));
            $label = "Tax rule #{$row['id']} «{$row['name']}»";
            if ((int) $row['level'] !== 1) {
                $this->skip("{$label}: a second-level tax is not carried.");
            } elseif (trim((string) $row['state']) !== '') {
                $this->skip("{$label}: a rule for a state is not carried.");
            } elseif (!in_array($country, ['', 'ANY'], true) && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                $this->skip("{$label}: «{$row['country']}» is no country.");
            } else {
                $key = $country === '' || $country === 'ANY' ? 'all' : $country;
                if (isset($seen[$key])) {
                    $this->skip("{$label}: {$key} already has a rate, the first is kept.");

                    continue;
                }
                $seen[$key] = true;
                $rate = number_format(max(0.0, min(100.0, (float) $row['taxrate'])), 2, '.', '');
                if (abs((float) $row['taxrate'] - (float) $rate) > 0.0001) {
                    $this->notes[] = "{$label}: {$row['taxrate']}% is kept as {$rate}% (two decimals).";
                }
                TaxRate::query()->updateOrCreate(['country' => $key], ['name' => mb_substr(trim((string) $row['name']) ?: 'VAT', 0, 255), 'rate' => $rate, 'whmcs_rate' => $rate]);
                $this->counts['rates']++;
            }
        }
    }

    private function settings(): void
    {
        $config = [];
        foreach ($this->rows("SELECT setting, value FROM tblconfiguration WHERE setting IN ('TaxEnabled', 'TaxType', 'TaxEUTaxExempt', 'TaxEUTaxValidation', 'TaxEUHomeCountry', 'TaxCode')") as $row) {
            $config[$row['setting']] = trim((string) $row['value']);
        }
        $on = fn (string $key) => in_array(strtolower($config[$key] ?? ''), ['on', '1', 'yes', 'true'], true);
        $home = strtoupper($config['TaxEUHomeCountry'] ?? '');
        $own = strtoupper(str_replace(' ', '', $config['TaxCode'] ?? ''));
        $settings = [
            'tax_enabled' => array_key_exists('TaxEnabled', $config) ? ['boolean', $on('TaxEnabled') ? '1' : '0'] : null,
            'tax_type' => in_array(strtolower($config['TaxType'] ?? ''), ['inclusive', 'exclusive'], true) ? ['string', strtolower($config['TaxType'])] : null,
            // The reverse charge and the 0% outside the EU: WHMCS's exemption for EU businesses, validated against VIES.
            'eu_vat_enabled' => array_key_exists('TaxEUTaxExempt', $config) ? ['boolean', $on('TaxEUTaxExempt') && $on('TaxEUTaxValidation') ? '1' : '0'] : null,
            'eu_vat_home_country' => in_array($home, StandardRates::EU, true) ? ['string', $home] : null,
            'eu_vat_requester' => preg_match('/^[A-Z]{2}[0-9A-Z]{8,12}$/', $own) === 1 && in_array(substr($own, 0, 2), StandardRates::EU, true) ? ['string', $own] : null,
        ];
        foreach ($settings as $key => $setting) {
            if ($setting === null) {
                continue;
            }
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $setting[1], 'type' => $setting[0]]);
            $this->counts['settings']++;
        }
        if (($config['TaxEUHomeCountry'] ?? '') !== '' && !in_array($home, StandardRates::EU, true)) {
            $this->notes[] = "WHMCS's EU home country «{$config['TaxEUHomeCountry']}» is no member state: set «EU VAT home country» and «Company country» by hand.";
        }
    }

    private function skip(string $note): void
    {
        $this->counts['skipped']++;
        $this->notes[] = $note;
    }

    /** @return list<array<string, mixed>> empty when the table is not there */
    private function rows(string $sql): array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
    }
}

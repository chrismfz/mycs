<?php

namespace MyCS\EuVatRates;

/**
 * The 27 member states' standard VAT rates, as last checked against TEDB (data/standard-rates.json, with the day).
 * A list to start an instance from, never a rate that applies by itself: each one goes to the instance's tax rates
 * only when it has none for that country, and a change of a rate is staff's to apply (it has a date it applies from).
 */
final class StandardRates
{
    /** The member states, ISO 3166-1 alpha-2 (Greece GR, not EL). */
    public const EU = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /** @var array{checked_on: string, source: string, rates: array<string, string>}|null */
    private static ?array $data = null;

    /** @return array<string, string> ISO country => rate («20.00»), all 27 */
    public static function rates(): array
    {
        return self::data()['rates'];
    }

    /** The day the list was checked against TEDB (Y-m-d). */
    public static function checkedOn(): string
    {
        return self::data()['checked_on'];
    }

    public static function source(): string
    {
        return self::data()['source'];
    }

    /** @return array{checked_on: string, source: string, rates: array<string, string>} */
    private static function data(): array
    {
        if (self::$data === null) {
            $data = json_decode((string) file_get_contents(__DIR__ . '/../data/standard-rates.json'), true, flags: JSON_THROW_ON_ERROR);
            $rates = [];
            foreach (self::EU as $iso) {
                $rates[$iso] = Rate::normalize($data['rates'][$iso] ?? throw new \UnexpectedValueException("No standard rate for {$iso}"));
            }
            self::$data = ['checked_on' => (string) $data['checked_on'], 'source' => (string) $data['source'], 'rates' => $rates];
        }

        return self::$data;
    }
}

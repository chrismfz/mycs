<?php

namespace App\Services\Import;

use PDO;

/**
 * The currency of each WHMCS account, read once: its tblcurrencies row, else the company's default currency
 * (tblcurrencies.default, as WHMCS's getCurrency falls back), else EUR (owner's decision 2026-11-08, sweep 6
 * S6-IMPORT-1). The one rule for everything imported in an account's currency (orders, services, invoices, credit, Mass
 * Pay, domains, the money journal, parity): an account with currency 0 or a deleted currency used to lose its orders,
 * services and invoices without a word, and its credit aborted the import.
 */
final class WhmcsClientCurrency
{
    public const FALLBACK = 'EUR';

    /** The company's default currency in WHMCS, or EUR when it names none. */
    public readonly string $default;

    /** @var array<int, string> account => currency code, for the accounts whose currency is a tblcurrencies row */
    private array $codes = [];

    /** @var list<int> the accounts whose currency names no tblcurrencies row: they take the default */
    public readonly array $defaulted;

    public function __construct(PDO $whmcs)
    {
        $currencies = [];
        $default = self::FALLBACK;
        foreach ($whmcs->query('SELECT * FROM tblcurrencies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currencies[(int) $row['id']] = (string) $row['code'];
            if ((int) ($row['default'] ?? 0) === 1) {
                $default = (string) $row['code'];
            }
        }
        $this->default = $default;

        $defaulted = [];
        foreach ($whmcs->query('SELECT id, currency FROM tblclients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($currencies[(int) $row['currency']])) {
                $this->codes[(int) $row['id']] = $currencies[(int) $row['currency']];
            } else {
                $defaulted[] = (int) $row['id'];
            }
        }
        $this->defaulted = $defaulted;
    }

    /** An account's currency code (an account WHMCS does not have: the default too). */
    public function code(int $clientId): string
    {
        return $this->codes[$clientId] ?? $this->default;
    }
}

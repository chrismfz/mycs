<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compares every credit balance with the journal (docs/money-trail.md §2): credits.amount must equal
 * Σ credit_in − Σ credit_out (reversals the other way). Read-only by default. With --write it rebuilds the balances
 * from the journal, one customer and currency at a time under the ledger's locks; never the other way round: the
 * journal is the record, the balance its cache.
 */
class CreditsCheck extends Command
{
    protected $signature = 'mycs:credits-check {--write : Rebuild the balances that differ from the journal}';

    protected $description = 'Compare account credit balances with the money journal (and optionally rebuild them)';

    public function handle(): int
    {
        // One snapshot for both reads (REPEATABLE READ): a move between them would show as a false mismatch. Balances
        // are read exactly, any size (not through toCents, which reads a huge amount as zero), so they show here.
        [$journal, $balances] = DB::transaction(fn () => [
            MoneyCheck::journalCredits(),
            Credit::query()->get(['client_id', 'currency_code', 'amount'])
                ->mapWithKeys(fn (Credit $credit) => [$credit->client_id . '|' . $credit->currency_code => (int) round((float) $credit->amount * 100)]),
        ]);

        $rows = [];
        foreach ($balances->keys()->merge(array_keys($journal))->unique() as $key) {
            $balance = $balances[$key] ?? 0;
            $expected = $journal[$key] ?? 0;
            if ($balance !== $expected) {
                [$clientId, $currency] = explode('|', $key, 2);
                $rows[] = [$clientId, $currency, self::money($balance), self::money($expected), self::money($balance - $expected)];
            }
        }

        if ($rows === []) {
            $this->info('Every credit balance matches the journal.');

            return self::SUCCESS;
        }

        $this->table(['Customer', 'Currency', 'Balance', 'Journal', 'Difference'], $rows);
        if (!$this->option('write')) {
            $this->warn(count($rows) . ' balance(s) differ from the journal. Nothing changed (use --write to rebuild them from the journal).');

            return self::FAILURE;
        }

        foreach ($rows as [$clientId, $currency]) {
            DB::transaction(function () use ($clientId, $currency) {
                // Under the ledger's locks, recomputed: the journal may have moved since the read above.
                $credit = (new CreditLedger)->lock((int) $clientId, $currency);
                $expected = MoneyCheck::journalCredits((int) $clientId, $currency)[$clientId . '|' . $currency] ?? 0;
                if ($credit === null) {
                    Credit::query()->create(['client_id' => (int) $clientId, 'currency_code' => $currency, 'amount' => $expected / 100]);
                } else {
                    $credit->amount = $expected / 100;
                    $credit->save();
                }
            });
        }
        $this->info(count($rows) . ' balance(s) rebuilt from the journal.');

        return self::SUCCESS;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

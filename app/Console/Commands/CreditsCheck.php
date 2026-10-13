<?php

namespace App\Console\Commands;

use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\MoneyEvent;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
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
        // One snapshot for both reads (REPEATABLE READ): a move between them would show as a false mismatch.
        [$journal, $balances] = DB::transaction(fn () => [
            $this->journalCents(),
            Credit::query()->get(['user_id', 'currency_code', 'amount'])
                ->mapWithKeys(fn (Credit $credit) => [$credit->user_id . '|' . $credit->currency_code => RecordPaymentService::toCents($credit->amount)]),
        ]);

        $rows = [];
        foreach ($balances->keys()->merge(array_keys($journal))->unique() as $key) {
            $balance = $balances[$key] ?? 0;
            $expected = $journal[$key] ?? 0;
            if ($balance !== $expected) {
                [$userId, $currency] = explode('|', $key, 2);
                $rows[] = [$userId, $currency, self::money($balance), self::money($expected), self::money($balance - $expected)];
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

        foreach ($rows as [$userId, $currency]) {
            DB::transaction(function () use ($userId, $currency) {
                // Under the ledger's locks, recomputed: the journal may have moved since the read above.
                $credit = (new CreditLedger)->lock((int) $userId, $currency);
                $expected = $this->journalCents((int) $userId, $currency)[$userId . '|' . $currency] ?? 0;
                if ($credit === null) {
                    Credit::query()->create(['user_id' => (int) $userId, 'currency_code' => $currency, 'amount' => $expected / 100]);
                } else {
                    $credit->amount = $expected / 100;
                    $credit->save();
                }
            });
        }
        $this->info(count($rows) . ' balance(s) rebuilt from the journal.');

        return self::SUCCESS;
    }

    /** @return array<string, int> "user|currency" => Σ credit effect in cents */
    private function journalCents(?int $userId = null, ?string $currency = null): array
    {
        $totals = [];
        MoneyEvent::query()->with('reverses')
            ->whereIn('kind', [MoneyEventKind::CreditIn->value, MoneyEventKind::CreditOut->value, MoneyEventKind::Reversal->value])
            ->whereNotNull('user_id')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId)->where('currency_code', $currency))
            ->orderBy('id')
            ->each(function (MoneyEvent $event) use (&$totals) {
                $effect = $event->creditEffectCents();
                if ($effect !== 0) {
                    $key = $event->user_id . '|' . $event->currency_code;
                    $totals[$key] = ($totals[$key] ?? 0) + $effect;
                }
            });

        return $totals;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

<?php

namespace App\Services\Tax;

use App\Helpers\NotificationHelper;
use App\Models\CronStat;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Services\Chat\ChatNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyCS\EuVatRates\Rate;
use MyCS\EuVatRates\StandardRates;
use MyCS\EuVatRates\Tedb;
use MyCS\EuVatRates\TedbUnavailable;
use Throwable;

/**
 * The EU member states' standard VAT rates (docs/eu-vat.md): the checked list into Tax Rates where a country has none,
 * and the weekly comparison with the European Commission's TEDB. The comparison changes nothing: a new rate applies
 * from a set day and has legal consequences, so staff are told and apply it themselves. Off by default (no call out
 * unless staff turn it on, CLAUDE.md); TEDB not answering changes nothing either, and is seen in Automation Status.
 */
final class EuVatRates
{
    /** Where the last check is kept (as the cron's last run). */
    public const STATE = 'eu_vat_rates_check_state';

    /** Weekly: a check older than this is late. */
    public const STALE_DAYS = 8;

    /** The same differences are told again after this many days, until staff change the rate (or TEDB does). */
    public const REMIND_DAYS = 6;

    public static function enabled(): bool
    {
        return (bool) config('settings.eu_vat_rates_check');
    }

    /**
     * The checked list into Tax Rates, only the countries with no rate there: a rate staff wrote is never changed.
     *
     * @return array<string, string> ISO country => rate, those added (or that would be, $dryRun)
     */
    public function seed(bool $dryRun = false): array
    {
        $existing = TaxRate::query()->pluck('country')->map(fn ($country) => strtoupper((string) $country))->all();
        $added = [];
        foreach (StandardRates::rates() as $iso => $rate) {
            if (in_array($iso, $existing, true)) {
                continue;
            }
            if (!$dryRun) {
                try {
                    TaxRate::query()->create(['name' => 'VAT ' . (config('app.countries')[$iso] ?? $iso), 'rate' => $rate, 'country' => $iso]);
                } catch (UniqueConstraintViolationException) {
                    continue; // written meanwhile (staff, another run): theirs stays
                }
            }
            $added[$iso] = $rate;
        }

        return $added;
    }

    /**
     * Today's standard rates from TEDB against Tax Rates. Only the member states the instance has a rate for are
     * compared: one without (myip has only GR) is not news.
     *
     * @return array{differences: list<array{country: string, ours: string, tedb: string}>, compared: int}
     *
     * @throws TedbUnavailable nothing compared, nothing changed; the failure kept for Automation Status
     */
    public function check(): array
    {
        try {
            $tedb = $this->fetch();
        } catch (Throwable $e) {
            $error = $e instanceof TedbUnavailable ? $e->getMessage() : 'TEDB did not answer: ' . $e->getMessage();
            Log::warning('EU VAT rates check: ' . $error);
            $this->keep(['failed_at' => now()->toIso8601String(), 'error' => mb_substr($error, 0, 500)]);

            throw $e instanceof TedbUnavailable ? $e : new TedbUnavailable($error, previous: $e);
        }

        $differences = [];
        $ours = TaxRate::query()->whereIn('country', StandardRates::EU)->pluck('rate', 'country');
        foreach ($ours as $country => $rate) {
            try {
                $rate = Rate::normalize($rate);
            } catch (\InvalidArgumentException) {
                $rate = (string) $rate;
            }
            if ($rate !== $tedb[$country]) {
                $differences[] = ['country' => (string) $country, 'ours' => $rate, 'tedb' => $tedb[$country]];
            }
        }
        $state = $this->state();
        $told = $state['told_at'] ?? null;
        $tell = $differences !== [] && (($state['told'] ?? null) !== $differences || $told === null
            || now()->subDays(self::REMIND_DAYS)->gt($told));
        if ($tell) {
            $this->tell($differences);
        }
        $this->keep(['checked_at' => now()->toIso8601String(), 'failed_at' => null, 'error' => null, 'compared' => $ours->count(),
            'differences' => $differences] + ($tell ? ['told' => $differences, 'told_at' => now()->toIso8601String()] : ($differences === [] ? ['told' => null, 'told_at' => null] : [])));

        return ['differences' => $differences, 'compared' => $ours->count()];
    }

    /**
     * The last check: when it ran, what it found, the last failure.
     *
     * @return array{checked_at?: string, failed_at?: ?string, error?: ?string, compared?: int, differences?: list<array{country: string, ours: string, tedb: string}>, told?: list<array<string, string>>, told_at?: string}
     */
    public function state(): array
    {
        $value = Setting::query()->where('key', self::STATE)->where('settingable_type', CronStat::class)->value('value');
        $state = is_string($value) ? json_decode($value, true) : null;

        return is_array($state) ? $state : [];
    }

    /** @return array<string, string> */
    private function fetch(): array
    {
        $response = Http::connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])
            ->withHeaders(['SOAPAction' => '"' . Tedb::SOAP_ACTION . '"'])
            ->withBody(Tedb::request(now()), 'text/xml; charset=utf-8')
            ->post(Tedb::ENDPOINT);
        if (!$response->successful()) {
            // TEDB answers a fault with a 500: its text says more than the status.
            try {
                Tedb::parse($response->body());
            } catch (TedbUnavailable $e) {
                throw new TedbUnavailable("HTTP {$response->status()}: {$e->getMessage()}");
            }

            throw new TedbUnavailable("HTTP {$response->status()}");
        }

        return Tedb::parse($response->body());
    }

    /** @param  list<array{country: string, ours: string, tedb: string}>  $differences */
    private function tell(array $differences): void
    {
        $lines = array_map(fn (array $difference) => sprintf('%s: %s%% in Tax Rates, %s%% in TEDB',
            $difference['country'], $difference['ours'], $difference['tedb']), $differences);
        NotificationHelper::sendSystemEmailNotification('EU VAT rate changed', e('The European Commission\'s Taxes in Europe Database (TEDB) gives a different standard VAT rate than Tax Rates for:')
            . '<br><pre>' . e(implode("\n", $lines)) . '</pre>'
            . e('Nothing was changed. A new rate applies from a set day: check it with the member state, then change the rate in Tax Rates on that day. TEDB is for information, not a legal source.'));
        ChatNotifier::notify('vat_rate_differs', '🧾 EU VAT rate differs from TEDB: ' . ChatNotifier::escape(implode('; ', $lines)));
    }

    /** @param  array<string, mixed>  $changes */
    private function keep(array $changes): void
    {
        Setting::query()->updateOrCreate(['key' => self::STATE, 'settingable_type' => CronStat::class],
            ['value' => json_encode(array_merge($this->state(), $changes)), 'type' => 'string']);
    }
}

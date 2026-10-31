<?php

namespace App\Services\Tax;

use App\Classes\Settings;
use App\Helpers\NotificationHelper;
use App\Models\CronStat;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Providers\SettingsProvider;
use App\Services\Chat\ChatCard;
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

    /** The Company country the last seed wrote (the only rate's, kept), or null. */
    public ?string $keptHomeCountry = null;

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
        // The company's country found as the only rate's (Settings::homeCountry, #524) is lost once there are more: kept
        // as Company country, so an account without a country pays what it did, never 0% (adversarial ADV-525-1).
        $implied = Settings::configuredHomeCountry() === null ? Settings::onlyRateCountry() : null;
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
        if ($implied !== null && $added !== [] && !$dryRun) {
            Setting::query()->updateOrCreate(['key' => 'tax_home_country', 'settingable_type' => null], ['value' => $implied, 'type' => 'string']);
            SettingsProvider::reload();
            $this->keptHomeCountry = $implied;
        }

        return $added;
    }

    /**
     * The rates the import brought from WHMCS (`whmcs_rate`), brought to the checked list where they differ and nobody
     * changed them since: a rate that still equals the one imported is WHMCS's, possibly out of date; one staff changed is
     * theirs and stays. A country is never added here (`seed()` does that). An updated rate stops being marked: it is ours.
     *
     * @return array{updated: array<string, array{from: string, to: string}>, kept: array<string, array{ours: string, list: string}>}
     */
    public function updateImported(bool $dryRun = false): array
    {
        $updated = [];
        $kept = [];
        $list = StandardRates::rates();
        foreach (TaxRate::query()->whereNotNull('whmcs_rate')->orderBy('country')->get() as $rate) {
            $iso = strtoupper((string) $rate->country);
            if (!isset($list[$iso]) || (float) $list[$iso] === (float) $rate->rate) {
                continue;
            }
            // Changed here since the import, or a 0% WHMCS rule for an EU country: an exemption somebody meant, never raised (ADV-562-2).
            if ((float) $rate->rate !== (float) $rate->whmcs_rate || (float) $rate->whmcs_rate === 0.0) {
                $kept[$iso] = ['ours' => (string) $rate->rate, 'list' => (string) $list[$iso]];

                continue;
            }
            $updated[$iso] = ['from' => (string) $rate->rate, 'to' => (string) $list[$iso]];
            if (!$dryRun) {
                $rate->forceFill(['rate' => $list[$iso], 'whmcs_rate' => null])->save();
            }
        }

        return ['updated' => $updated, 'kept' => $kept];
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

        ['differences' => $differences, 'compared' => $compared] = $this->compare($tedb);
        $state = $this->state();
        $told = $state['told_at'] ?? null;
        $tell = $differences !== [] && (($state['told'] ?? null) !== $differences || $told === null
            || now()->subDays(self::REMIND_DAYS)->gt($told));
        if ($tell) {
            // A mail that fails is not a check that failed: the state is kept, the failure reported (code review).
            try {
                $this->tell($differences);
            } catch (Throwable $e) {
                report($e);
                $tell = false; // not told: told again on the next run
            }
        }
        // TEDB's rates kept, so Automation Status compares them with Tax Rates as they are now (code review).
        $this->keep(['checked_at' => now()->toIso8601String(), 'failed_at' => null, 'error' => null, 'compared' => $compared,
            'tedb' => $tedb, 'differences' => $differences] + ($tell ? ['told' => $differences, 'told_at' => now()->toIso8601String()] : ($differences === [] ? ['told' => null, 'told_at' => null] : [])));

        return ['differences' => $differences, 'compared' => $compared];
    }

    /**
     * Tax Rates now against TEDB's rates: only the member states the instance has a rate for, by country (the same
     * differences compare equal whatever order the database reads them in). A country written in lower case is its
     * upper case one (MariaDB's collation finds it).
     *
     * @param  array<string, string>  $tedb
     * @return array{differences: list<array{country: string, ours: string, tedb: string}>, compared: int}
     */
    public function compare(array $tedb): array
    {
        $differences = [];
        $compared = 0;
        $ours = TaxRate::query()->whereIn('country', StandardRates::EU)->orderBy('country')->get(['country', 'rate']);
        foreach ($ours as $row) {
            $country = strtoupper(trim((string) $row->country));
            if (!isset($tedb[$country])) {
                continue;
            }
            $compared++;
            try {
                $rate = Rate::normalize($row->rate);
            } catch (\InvalidArgumentException) {
                $rate = (string) $row->rate;
            }
            if ($rate !== $tedb[$country]) {
                $differences[] = ['country' => $country, 'ours' => $rate, 'tedb' => $tedb[$country]];
            }
        }
        usort($differences, fn (array $a, array $b) => strcmp($a['country'], $b['country']));

        return ['differences' => $differences, 'compared' => $compared];
    }

    /**
     * The last check: when it ran, what it found, the last failure.
     *
     * @return array{checked_at?: string, failed_at?: ?string, error?: ?string, compared?: int, tedb?: array<string, string>, differences?: list<array{country: string, ours: string, tedb: string}>, told?: list<array<string, string>>, told_at?: string}
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
        ChatNotifier::notify('vat_rate_differs', new ChatCard('EU VAT rate differs from TEDB', null,
            ChatNotifier::escape(implode('; ', $lines)) . "\n" . 'Nothing was changed: check it with the member state, then change the rate in Tax Rates on the day it applies.', ChatCard::GREY));
    }

    /** @param  array<string, mixed>  $changes */
    private function keep(array $changes): void
    {
        Setting::query()->updateOrCreate(['key' => self::STATE, 'settingable_type' => CronStat::class],
            ['value' => json_encode(array_merge($this->state(), $changes)), 'type' => 'string']);
    }
}

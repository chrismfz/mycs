<?php

namespace App\Console\Commands;

use App\Providers\SettingsProvider;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Simulation\Ledger;
use App\Services\Simulation\SimPay;
use App\Services\Simulation\SimRegistry;
use App\Services\Simulation\SimServer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The real cron, day by day, on a **copy** of an imported database, with the outside world replaced (ROADMAP 9β, 2;
 * docs/importer.md «Simulation»): servers, gateways and registrars are stand-ins that remember what they are asked and
 * answer «done», the mail goes to the array mailer, the clock is moved a day at a time. Out comes a ledger of what the
 * cron would have done each day: invoices, charges, suspensions, terminations, renewals, mails.
 *
 * It is made so that nothing can escape: it runs only after `--confirm-database=` typed, only where an outbound
 * connection **fails** (run it with `bin/simulate`, which opens a network-less namespace and makes the copy), and it
 * changes the database it is given (extensions swapped for the stand-ins, registrar connections switched on): never the
 * one a site serves.
 */
class Simulate extends Command
{
    protected $signature = 'mycs:simulate
        {--confirm-database= : this instance\'s database name, typed: the simulation changes it (a copy, never a live one)}
        {--from= : the first day (Y-m-d); default: today}
        {--days=7 : how many days}
        {--keep-gateways : leave the card gateways enabled or disabled as they are (default: enabled, as the cut-over makes them: an import brings them disabled)}
        {--decline-clients= : client ids, comma-separated, whose saved card is declined}
        {--out= : write the ledger (day, event, ref, detail) to this CSV file}';

    /** @var (\Closure(): bool)|null whether the outside is reachable (tests: a fixed answer) */
    public static ?\Closure $networkProbe = null;

    /** @var (\Closure(string): bool)|null whether a database name is a copy made for this (tests: yes); default: it ends in «_sim», as bin/simulate names it */
    public static ?\Closure $isCopy = null;

    /** The extensions the simulation puts in place of every server and card gateway: names no test or extension has. */
    private const SERVER = 'MycsSimServer';

    private const GATEWAY = 'MycsSimPay';

    /** Servers with no API: their actions are a staff task, which is what the cron makes of them, and what the forecast must show. */
    private const REAL_SERVERS = ['OfflineVm', 'OfflineDedicated', 'OfflineSsl', 'NixpalLicensing'];

    protected $description = 'Run the cron day by day on a copy of an import, with servers, gateways, registrars and mail replaced; list what it would do';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if ((string) $this->option('confirm-database') !== $database) {
            $this->error("Type the database name to confirm: --confirm-database={$database}. The simulation changes it: give it a COPY, never one a site serves (bin/simulate makes the copy).");

            return self::FAILURE;
        }
        if (!(self::$isCopy ?? fn (string $name) => str_ends_with($name, '_sim'))($database)) {
            $this->error("«{$database}» is not a copy made for the simulation (its name ends in «_sim»): bin/simulate makes one. The simulation changes the database it runs on.");

            return self::FAILURE;
        }
        // Nothing queued for a worker: a mail or a server action queued in a database a worker reads would be done for real.
        if (config('queue.default') !== 'sync') {
            $this->error('The queue is «' . config('queue.default') . '», not «sync»: a queued mail or server job would be run by a worker. Use bin/simulate (it sets QUEUE_CONNECTION=sync).');

            return self::FAILURE;
        }
        if ((self::$networkProbe ?? self::outboundWorks(...))()) {
            $this->error('The network is open from here. The simulation runs only where an outbound connection fails: use bin/simulate (it opens a namespace without a network).');

            return self::FAILURE;
        }
        $days = max(1, min(120, (int) $this->option('days')));
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay() : CarbonImmutable::today();
        foreach (array_filter(array_map('intval', explode(',', (string) $this->option('decline-clients')))) as $client) {
            SimPay::$declined[$client] = true;
        }
        $this->swap((bool) $this->option('keep-gateways'));
        Cache::flush(); // once: the cache's own marks (a notice told once) must hold from one day to the next
        $ledger = new Ledger;
        $time = (string) config('settings.cronjob_time', '09:00');
        $this->info("Simulating {$days} day(s) from {$from->toDateString()} on «{$database}»: the cron at {$time}, then the hourly dunning run three hours after.");
        $summary = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i);
            Ledger::forget();
            $failures = 0;
            $daily = CarbonImmutable::parse($day->toDateString() . ' ' . $time);
            // The hourly dunning run three hours on, never past midnight into another day (a late cron time).
            $hourly = $daily->addHours(3)->isSameDay($daily) ? $daily->addHours(3) : $daily->setTime(23, 30);
            foreach ([[$daily, []], [$hourly, ['--dunning-only' => true]]] as [$now, $options]) {
                $at = $now->format('H:i');
                Carbon::setTestNow($now);
                CarbonImmutable::setTestNow($now);
                try {
                    $failures += Artisan::call('app:cron-job', $options) === 0 ? 0 : 1;
                } catch (Throwable $e) {
                    $failures++;
                    $this->warn("{$day->toDateString()} {$at}: the cron stopped: " . class_basename($e));
                    report($e);
                }
            }
            $lines = $ledger->day($day->toDateString());
            $counts = array_count_values(array_column($lines, 1));
            $summary[] = [$day->toDateString(), $counts['invoice created'] ?? 0, ($counts['card charged'] ?? 0) . '/' . ($counts['card declined'] ?? 0),
                $counts['server asked'] ?? 0, $counts['registrar asked'] ?? 0, $counts['mail'] ?? 0, $failures ?: ''];
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        $this->table(['Day', 'Invoices', 'Cards ok/declined', 'Server asked', 'Registrar asked', 'Mails', 'Cron stopped'], $summary);
        $byEvent = array_count_values(array_column($ledger->lines(), 1));
        arsort($byEvent);
        $this->table(['Event', 'Times'], array_map(fn ($event, $n) => [$event, $n], array_keys($byEvent), $byEvent));
        if (($path = (string) $this->option('out')) !== '') {
            $handle = fopen($path, 'w');
            fputcsv($handle, ['day', 'event', 'ref', 'detail']);
            foreach ($ledger->lines() as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
            $this->line("The ledger ({$path}): " . count($ledger->lines()) . ' line(s).');
        }

        return self::SUCCESS;
    }

    /** The stand-ins in place of the world: extensions, registrars, mail. */
    private function swap(bool $keepGateways): void
    {
        class_exists('MyCS\\Extensions\\Servers\\' . self::SERVER . '\\' . self::SERVER, false) || class_alias(SimServer::class, 'MyCS\\Extensions\\Servers\\' . self::SERVER . '\\' . self::SERVER);
        class_exists('MyCS\\Extensions\\Gateways\\' . self::GATEWAY . '\\' . self::GATEWAY, false) || class_alias(SimPay::class, 'MyCS\\Extensions\\Gateways\\' . self::GATEWAY . '\\' . self::GATEWAY);
        DB::table('extensions')->where('type', 'server')->whereNotIn('extension', self::REAL_SERVERS)->update(['extension' => self::SERVER, 'enabled' => true]);
        // Only a gateway that charges a saved method is a card gateway (Stripe, PayPal); a bank transfer and the like stay as they are.
        foreach (DB::table('extensions')->where('type', 'gateway')->get(['id', 'extension']) as $gateway) {
            $class = 'MyCS\\Extensions\\Gateways\\' . $gateway->extension . '\\' . $gateway->extension;
            if (class_exists($class) && method_exists($class, 'charge')) {
                DB::table('extensions')->where('id', $gateway->id)->update(['extension' => self::GATEWAY] + ($keepGateways ? [] : ['enabled' => true]));
            }
        }
        DB::table('domain_registrar_connections')->where('registrar', '!=', 'manual')->update(['is_active' => true, 'mode' => 'production']);
        $registry = new SimRegistry;
        app()->instance(DomainRegistrarRegistry::class, $registry);
        app()->instance(DomainRegistrarFactory::class, new DomainRegistrarFactory($registry));
        config(['mail.default' => 'array']);
        DB::table('settings')->whereNull('settingable_type')->where('key', 'mail_disable')->update(['value' => '0']);
        SettingsProvider::flushCache();
    }

    /** Whether a connection to the outside succeeds: in a namespace without a network it fails at once. */
    private static function outboundWorks(): bool
    {
        foreach (['1.1.1.1:53', '8.8.8.8:53'] as $address) {
            $socket = @stream_socket_client("tcp://{$address}", $code, $message, 2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
        }

        return false;
    }
}

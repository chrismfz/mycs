<?php

namespace App\Console\Commands\Ops;

use App\Models\License;
use App\Services\Ops\ZlicProbe;
use Illuminate\Console\Command;

/** What zlic.php would answer a license's client from an address, writing nothing (docs/ops.md). */
class ZlicCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:zlic {key : License key} {--ip= : The caller\'s address (default: its bound one)} {--json : JSON output}';

    protected $description = 'Dry-run a license check: the answer, whether it would bind, what it would log';

    public function handle(): int
    {
        $ip = (string) ($this->option('ip') ?: '');
        if ($ip === '') {
            $bound = License::query()->where('license_key', $this->argument('key'))->value('valid_ip');
            $ip = trim(explode(',', (string) $bound)[0]) ?: '192.0.2.1';
        }
        $this->report(['ip' => $ip] + ZlicProbe::answer((string) $this->argument('key'), $ip));

        return self::SUCCESS;
    }
}

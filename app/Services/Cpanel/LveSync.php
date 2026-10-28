<?php

namespace App\Services\Cpanel;

use App\Helpers\ExtensionHelper;
use App\Models\CpanelAccount;
use App\Models\CpanelLveSample;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use MyCS\Extensions\Servers\CPanel\CPanel;
use RuntimeException;

/**
 * CloudLinux's limits and each account's use of them, read into cpanel_lve_samples (owner 2026-10-28, stage 3): the
 * server's accounts as cpanel:sync last listed them, suspended ones left out, one reading each; the account's limits kept
 * on it, its readings two days (the peak of the last 24 hours). A server without CloudLinux is told after one call. Reads
 * only.
 */
final class LveSync
{
    /** Readings kept this long (the peak is of the last 24 hours). */
    public const KEEP_HOURS = 48;

    /** @return array{read: int, cloudlinux: bool} */
    public static function sync(Server $server): array
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof CPanel) {
            throw new \InvalidArgumentException("{$server->name} is not a cPanel server.");
        }
        $lock = Cache::lock("cpanel.lve.{$server->id}", 1800);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again later.");
        }
        try {
            $accounts = CpanelAccount::query()->where('server_id', $server->id)->whereNull('gone_at')->where('suspended', false)
                ->get()->keyBy('user');
            $read = $accounts->isEmpty() ? [] : $extension->lveUsages($accounts->keys()->map(fn ($user) => (string) $user)->all());
            $now = now();
            foreach ($read as $user => $lve) {
                $account = $accounts->get($user);
                if (!$account instanceof CpanelAccount) {
                    continue;
                }
                $used = fn (string $key) => $lve[$key]['used'] ?? null;
                $limit = fn (string $key) => $lve[$key]['limit'] ?? null;
                $mb = fn (?int $bytes) => $bytes === null ? null : (int) round($bytes / 1048576);
                $kb = fn (?int $bytes) => $bytes === null ? null : (int) round($bytes / 1024);
                $account->update(['lve_memory_limit_mb' => $mb($limit('memory')), 'lve_entry_processes_limit' => $limit('entry_processes'),
                    'lve_processes_limit' => $limit('processes'), 'lve_io_limit_kbs' => $kb($limit('io')), 'lve_iops_limit' => $limit('iops'),
                    'lve_read_at' => $now]);
                CpanelLveSample::query()->create(['cpanel_account_id' => $account->id, 'sampled_at' => $now,
                    'cpu' => $used('cpu') === null ? null : min(65535, $used('cpu')), 'memory_mb' => $mb($used('memory')),
                    'entry_processes' => $used('entry_processes'), 'processes' => $used('processes'), 'io_kbs' => $kb($used('io')), 'iops' => $used('iops')]);
            }
            CpanelLveSample::query()->whereIn('cpanel_account_id', CpanelAccount::query()->where('server_id', $server->id)->select('id'))
                ->where('sampled_at', '<', $now->copy()->subHours(self::KEEP_HOURS))->delete();

            return ['read' => count($read), 'cloudlinux' => $read !== [] || $accounts->isEmpty()];
        } finally {
            $lock->release();
        }
    }
}

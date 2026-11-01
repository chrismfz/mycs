<?php

namespace App\Services\Money;

use App\Helpers\ExtensionHelper;
use App\Jobs\RefreshGatewayBalances;
use App\Models\Gateway;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What Stripe and PayPal hold (Bank totals, owner 2026-10-24), read by `gateways:balances` and kept: a page never calls a
 * gateway. A gateway that cannot be read keeps what was last read, with the error said. Saving one on its page reads them
 * again on the queue (owner 2026-11-02: a gateway set up just now waited up to an hour to be seen).
 */
final class GatewayBalances
{
    public const KEY = 'gateway_balances';

    /** The gateways read, by their extension: those whose class has a balance(). */
    public const GATEWAYS = ['Stripe', 'PayPal'];

    /** @return array<string, array{label: string, rows: list<array{currency: string, available: string, pending: string}>, read_at: ?string, error: ?string}> */
    public static function stored(): array
    {
        return (array) Cache::get(self::KEY, []);
    }

    /**
     * What the dashboard shows: each enabled gateway with what was last read of it, one not read yet with no rows. A
     * gateway turned off since is not shown, though still kept until the next read.
     *
     * @return array<string, array{label: string, rows: list<array{currency: string, available: string, pending: string}>, read_at: ?string, error: ?string}>
     */
    public static function shown(): array
    {
        $stored = self::stored();
        $shown = [];
        foreach (self::enabled()->get() as $gateway) {
            $key = (string) $gateway->extension;
            $read = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $shown[$key] = ['label' => (string) ($gateway->name ?: $key)] + $read + ['rows' => [], 'read_at' => null, 'error' => null];
        }

        return $shown;
    }

    /** A gateway saved on its page: read again soon, turned on, off or with new keys. */
    public static function readSoon(Gateway $gateway): void
    {
        if (in_array($gateway->extension, self::GATEWAYS, true)) {
            RefreshGatewayBalances::dispatch()->afterCommit(); // its settings read once saved
        }
    }

    /**
     * One read at a time (review of #576): the hourly one, begun with the old key, would write over the one a save
     * began with the new key. The secrets to mask are read again, as a queue worker's may be older than a key saved now.
     *
     * @return array<string, array{label: string, rows: list<array{currency: string, available: string, pending: string}>, read_at: ?string, error: ?string}>
     */
    public static function read(): array
    {
        return Cache::lock(self::KEY . ':read', 120)->block(100, function (): array {
            Redact::forget();

            return self::readNow();
        });
    }

    /** @return array<string, array{label: string, rows: list<array{currency: string, available: string, pending: string}>, read_at: ?string, error: ?string}> */
    private static function readNow(): array
    {
        $stored = self::stored();
        $balances = [];
        foreach (self::enabled()->with('settings')->get() as $gateway) {
            $key = (string) $gateway->extension;
            try {
                $extension = ExtensionHelper::getExtension('gateway', $key, $gateway->settings);
                $balances[$key] = ['label' => (string) ($gateway->name ?: $key), 'rows' => $extension->balance(), 'read_at' => now()->toIso8601String(), 'error' => null];
            } catch (Throwable $e) {
                report($e);
                $balances[$key] = ['label' => (string) ($gateway->name ?: $key), 'rows' => $stored[$key]['rows'] ?? [],
                    'read_at' => $stored[$key]['read_at'] ?? null, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 300)];
            }
        }
        Cache::forever(self::KEY, $balances);

        return $balances;
    }

    /** @return Builder<Gateway> */
    private static function enabled(): Builder
    {
        return Gateway::query()->whereIn('extension', self::GATEWAYS)->where('enabled', true)->orderBy('id');
    }
}

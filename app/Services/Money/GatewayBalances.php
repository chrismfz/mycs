<?php

namespace App\Services\Money;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Support\Redact;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What Stripe and PayPal hold (Bank totals, owner 2026-10-24), read by `gateways:balances` and kept: a page never calls a
 * gateway. A gateway that cannot be read keeps what was last read, with the error said.
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

    /** @return array<string, array{label: string, rows: list<array{currency: string, available: string, pending: string}>, read_at: ?string, error: ?string}> */
    public static function read(): array
    {
        $stored = self::stored();
        $balances = [];
        foreach (Gateway::query()->whereIn('extension', self::GATEWAYS)->where('enabled', true)->with('settings')->get() as $gateway) {
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
}

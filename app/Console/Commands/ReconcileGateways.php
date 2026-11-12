<?php

namespace App\Console\Commands;

use App\Helpers\NotificationHelper;
use App\Models\GatewayTransaction;
use App\Services\Money\PaymentsLog;
use App\Services\Money\Reconcile\GatewayReconcile;
use Illuminate\Console\Command;

/**
 * The daily comparison of each gateway's own list of transactions with what is recorded here (GatewayReconcile,
 * docs/payments-tracking.md). Read-only at the gateway and on the money: it writes gateway_transactions only. What
 * differs is told by the money check (`gateway_unreconciled`), which runs after it. A list that cannot be read (no
 * permission, the gateway refusing) is said to staff once, when it starts failing; the money check repeats it.
 */
class ReconcileGateways extends Command
{
    protected $signature = 'gateways:reconcile
        {--days= : Days to look back, instead of the gateway\'s setting (never before its «Reconcile from»; at most 90)}';

    protected $description = 'Compare each gateway\'s own list of transactions (PayPal) with what is recorded here; read-only';

    public function handle(): int
    {
        $days = $this->option('days');
        if ($days !== null && (!ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--days is a whole number of days.');

            return self::INVALID;
        }
        $gateways = GatewayReconcile::gateways();
        if ($gateways === []) {
            $this->info('No gateway is compared (PayPal: fill «Reconcile from» on its page).');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($gateways as ['gateway' => $gateway, 'extension' => $extension]) {
            $name = (string) $gateway->extension;
            $result = (new GatewayReconcile)->run($gateway, $extension, $days === null ? null : (int) $days);
            if (!$result['read']) {
                $failed = true;
                $message = "{$name}: its list of transactions could not be read ({$result['error']}). Nothing was compared.";
                $this->error($message);
                PaymentsLog::warning('gateways:reconcile: ' . $message);
                if ($result['first_failure']) {
                    NotificationHelper::sendSystemEmailNotification("{$name} transactions not read", e($message) . '<br><br>'
                        . 'The daily comparison of ' . e($name) . '\'s transactions with what is recorded here did not run. It is tried again every day, '
                        . 'and the money check says so while it lasts; this mail is sent once. If the error is about permissions (403, NOT_AUTHORIZED): '
                        . 'the PayPal REST app needs «Transaction Search» ticked (developer.paypal.com → Apps &amp; Credentials → the app → Features), '
                        . 'and PayPal may take some hours to give the permission to new tokens (docs/payments-tracking.md).');
                }

                continue;
            }
            $this->line("{$name}: {$result['new']} new difference(s), {$result['resolved']} resolved, {$result['open']} open.");
            PaymentsLog::info("gateways:reconcile: {$name}: {$result['new']} new difference(s), {$result['resolved']} resolved, {$result['open']} open.");
        }

        $open = GatewayTransaction::query()->where('status', GatewayTransaction::OPEN)->orderBy('occurred_at')->get();
        if ($open->isNotEmpty()) {
            $this->table(['Gateway', 'What', 'Id', 'Difference'], $open->map(fn (GatewayTransaction $row) => [$row->gateway, $row->what, $row->reference, $row->describe()])->all());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

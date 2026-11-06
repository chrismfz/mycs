<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Services\Money\GatewayBalances;
use Illuminate\Console\Command;
use Throwable;

/**
 * The cut-over's gateways on (owner 2026-11-06: «they should all come, with their keys, and be active»). The import
 * brings them disabled with what WHMCS gave (WhmcsCredentialImport, importPaymentWays); this turns on each one that is
 * whole and answers: Stripe with its keys, once Stripe answers them, and its webhook made; PayPal with its client id and
 * secret, once PayPal gives a token, and its webhook made or found; Bank transfer with its accounts. Any other stays for
 * staff (Cardlink: its keys are not readable outside WHMCS). Run after the host is the company's (cutover-runbook): the
 * webhooks take this site's address of the moment. Never on a rehearsal's database: its scheduler would charge for real.
 */
class EnableImportedGateways extends Command
{
    protected $signature = 'app:enable-imported-gateways {--yes : no question (the cut-over\'s script)}';

    protected $description = 'Turn on the imported gateways that are whole and answer (Stripe, PayPal, Bank transfer), with their webhooks';

    public function handle(): int
    {
        $gateways = Gateway::query()->where('enabled', false)->orderBy('id')->get();
        if ($gateways->isEmpty()) {
            $this->info('No disabled gateway.');

            return self::SUCCESS;
        }
        $this->line('This site\'s address, which the webhooks will be made for: ' . url('/'));
        if (!$this->option('yes') && !$this->confirm('Turn on the disabled gateways that are whole and answer, with live charging from this database?')) {
            return self::FAILURE;
        }

        $failed = false;
        foreach ($gateways as $gateway) {
            try {
                $why = match ($gateway->extension) {
                    'Stripe' => $this->stripe($gateway),
                    'PayPal' => $this->paypal($gateway),
                    'BankTransfer' => $this->setting($gateway, 'accounts') === '' ? 'its accounts are empty' : null,
                    default => 'set it up and enable it by hand',
                };
            } catch (Throwable $e) {
                report($e);
                $why = 'it did not answer: ' . mb_substr($e->getMessage(), 0, 200);
                $failed = true;
            }
            if ($why !== null) {
                $this->warn("Gateway «{$gateway->name}» ({$gateway->extension}) left disabled: {$why}.");

                continue;
            }
            $gateway->forceFill(['enabled' => true])->save();
            GatewayBalances::readSoon($gateway);
            $this->info("Gateway «{$gateway->name}» ({$gateway->extension}) enabled.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Its keys answer (its balance, read only), then its webhook is made for this address (Stripe::updated). */
    private function stripe(Gateway $gateway): ?string
    {
        if ($this->setting($gateway, 'stripe_secret_key') === '' || $this->setting($gateway, 'stripe_publishable_key') === '') {
            return 'its keys did not come';
        }
        ExtensionHelper::call($gateway, 'balance');
        ExtensionHelper::call($gateway, 'updated', [$gateway]);
        $status = ExtensionHelper::call($gateway->refresh(), 'webhookStatus', [$gateway]);

        return ($status['ok'] ?? false) ? null : 'its webhook was not made (' . ($status['text'] ?? '') . ')';
    }

    /** A token for its client id and secret, then this site's webhook on its app, whose id it keeps. */
    private function paypal(Gateway $gateway): ?string
    {
        if ($this->setting($gateway, 'client_id') === '' || $this->setting($gateway, 'client_secret') === '') {
            return 'its client id and secret did not come';
        }
        ExtensionHelper::call($gateway, 'checkConnection');
        $id = (string) ExtensionHelper::call($gateway, 'ensureWebhook');
        if ($id === '') {
            return 'PayPal gave no webhook id';
        }
        $gateway->settings()->updateOrCreate(['key' => 'webhook_id'], ['value' => $id, 'type' => 'string', 'encrypted' => false]);
        $this->line("Gateway «{$gateway->name}»: webhook {$id} for " . route('extensions.gateways.paypal.webhook') . '.');

        return null;
    }

    /** Decrypted (the model: ->value() would be the ciphertext). */
    private function setting(Gateway $gateway, string $key): string
    {
        return trim((string) $gateway->settings()->where('key', $key)->first()?->value);
    }
}

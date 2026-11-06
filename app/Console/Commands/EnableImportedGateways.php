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
 * Test keys and PayPal's sandbox stay off unless --allow-test: on the real site a test card would pay a real invoice.
 */
class EnableImportedGateways extends Command
{
    protected $signature = 'app:enable-imported-gateways {--yes : no question (the cut-over\'s script)}
        {--allow-test : also Stripe test keys and PayPal\'s sandbox (a test site only: a test card would pay a real invoice)}';

    protected $description = 'Turn on the imported gateways that are whole and answer (Stripe, PayPal, Bank transfer), with their webhooks';

    public function handle(): int
    {
        $gateways = Gateway::query()->where('enabled', false)->orderBy('id')->get();
        if ($gateways->isEmpty()) {
            $this->info('No disabled gateway.');

            return self::SUCCESS;
        }
        $this->line('This site\'s address, which the webhooks will be made for: ' . url('/'));
        // A live webhook to an address that is not https points nowhere (an unset APP_URL is http://localhost): ADV-680-7.
        if (parse_url(url('/'), PHP_URL_SCHEME) !== 'https' && !$this->option('allow-test')) {
            $this->error('This site\'s address is not https: set APP_URL to the company\'s host (after the host switch) and run config:cache first. Nothing changed.');

            return self::FAILURE;
        }
        if (!$this->option('yes') && !$this->confirm('Turn on the disabled gateways that are whole and answer? This database then charges customers for real.')) {
            return self::FAILURE;
        }

        $failed = false;
        foreach ($gateways as $gateway) {
            // Turned on by this command once, and off since: staff's choice, never undone by a re-run (ADV-680-6).
            if ($gateway->settings()->where('key', self::MARK)->exists()) {
                $this->line("Gateway «{$gateway->name}» ({$gateway->extension}) was turned off since this command enabled it: left as staff set it.");

                continue;
            }
            // Another of its kind already on: two answer one webhook route, and Stripe's would delete the other's (ADV-680-3).
            if (in_array($gateway->extension, ['Stripe', 'PayPal'], true) && Gateway::query()->where('extension', $gateway->extension)->where('enabled', true)->whereKeyNot($gateway->id)->exists()) {
                $this->warn("Gateway «{$gateway->name}» ({$gateway->extension}) left disabled: another {$gateway->extension} gateway is already on.");
                $failed = true;

                continue;
            }
            try {
                [$why, $fatal] = match ($gateway->extension) {
                    'Stripe' => $this->stripe($gateway),
                    'PayPal' => $this->paypal($gateway),
                    'BankTransfer' => [$this->setting($gateway, 'accounts') === '' ? 'its accounts are empty' : null, false],
                    default => ['set it up and enable it by hand', false],
                };
                // One that came whole but was not turned on fails the run (code review of #680): a cut-over script
                // goes on only when what should charge does. Missing keys and the ones for staff are said only.
                $failed = $failed || $fatal;
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
            $gateway->settings()->updateOrCreate(['key' => self::MARK], ['value' => now()->toIso8601String(), 'type' => 'string', 'encrypted' => false]);
            GatewayBalances::readSoon($gateway);
            $this->info("Gateway «{$gateway->name}» ({$gateway->extension}) enabled.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** The gateway's setting saying when this command turned it on. */
    public const MARK = 'enabled_at_cutover';

    /**
     * Its webhook is made for this address (Stripe::updated: its keys answer when it is, a restricted key with the
     * Webhook Endpoints permission included). Test keys only with --allow-test (code review of #680).
     *
     * @return array{0: ?string, 1: bool} why it stays off (none: on), and whether that fails the run
     */
    private function stripe(Gateway $gateway): array
    {
        $secret = $this->setting($gateway, 'stripe_secret_key');
        if ($secret === '' || $this->setting($gateway, 'stripe_publishable_key') === '') {
            return ['its keys did not come', false];
        }
        // Both of one mode (ADV-680-8: a live secret with a test publishable key breaks every checkout), as the importer asks.
        if (preg_match('/^(sk|rk)_(live|test)_/', $secret, $s) !== 1 || preg_match('/^pk_(live|test)_/', $this->setting($gateway, 'stripe_publishable_key'), $p) !== 1
            || $s[2] !== $p[1]) {
            return ['its secret and publishable keys are not of one mode', true];
        }
        if ($s[2] === 'test' && !$this->option('allow-test')) {
            return ['its keys are TEST keys (--allow-test on a test site only)', true];
        }
        ExtensionHelper::call($gateway, 'updated', [$gateway]);
        $status = ExtensionHelper::call($gateway->refresh(), 'webhookStatus', [$gateway]);

        return ($status['ok'] ?? false) ? [null, false] : ['its webhook was not made (' . ($status['text'] ?? '') . ')', true];
    }

    /**
     * A token for its client id and secret, then this site's webhook on its app, whose id it keeps.
     *
     * @return array{0: ?string, 1: bool} as stripe()
     */
    private function paypal(Gateway $gateway): array
    {
        if ($this->setting($gateway, 'client_id') === '' || $this->setting($gateway, 'client_secret') === '') {
            return ['its client id and secret did not come', false];
        }
        if (in_array(strtolower($this->setting($gateway, 'test_mode')), ['1', 'true', 'on', 'yes'], true) && !$this->option('allow-test')) {
            return ['it runs in the SANDBOX (--allow-test on a test site only)', true];
        }
        ExtensionHelper::call($gateway, 'checkConnection');
        $id = (string) ExtensionHelper::call($gateway, 'ensureWebhook');
        if ($id === '') {
            return ['PayPal gave no webhook id', true];
        }
        $gateway->settings()->updateOrCreate(['key' => 'webhook_id'], ['value' => $id, 'type' => 'string', 'encrypted' => false]);
        $this->line("Gateway «{$gateway->name}»: webhook {$id} for " . route('extensions.gateways.paypal.webhook') . '.');

        return [null, false];
    }

    /** Decrypted (the model: ->value() would be the ciphertext). */
    private function setting(Gateway $gateway, string $key): string
    {
        return trim((string) $gateway->settings()->where('key', $key)->first()?->value);
    }
}

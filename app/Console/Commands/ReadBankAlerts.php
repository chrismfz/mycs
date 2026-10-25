<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Services\BankAlerts\AlertReader;
use App\Services\BankAlerts\ImapAlertMailbox;
use App\Services\BankAlerts\PaymentMatcher;
use App\Support\Redact;
use Illuminate\Console\Command;
use MyCS\Extensions\Gateways\BankTransfer\BankTransfer;
use Throwable;

/** The banks' alert emails read into Payments tracking (the Bank transfer gateway's mailbox), the mailbox untouched. */
class ReadBankAlerts extends Command
{
    protected $signature = 'bank:alerts';

    protected $description = 'Read the banks\' alert emails (credits, debits, balances) without changing the mailbox';

    public function handle(): int
    {
        $gateway = Gateway::query()->where('extension', 'BankTransfer')->first();
        $extension = $gateway !== null ? ExtensionHelper::getExtension('gateway', 'BankTransfer', $gateway->settings) : null;
        $settings = $extension instanceof BankTransfer ? $extension->alertSettings() : null;
        if ($settings === null) {
            $this->line('Reading the bank alerts is off (Bank transfer gateway).');

            return self::SUCCESS;
        }
        $mailbox = new ImapAlertMailbox($settings);
        try {
            $summary = (new AlertReader($mailbox, $settings))->run();
        } catch (Throwable $e) {
            report($e);
            $this->error(self::scrub($e->getMessage(), $settings));

            return self::FAILURE;
        } finally {
            $mailbox->disconnect();
        }
        $this->line("{$summary['read']} mail(s) read, {$summary['new']} new movement(s)");
        if (($matched = self::match($settings)) !== null) {
            $this->line("{$matched['matched']} credit(s) matched to their invoice, {$matched['left']} left for staff");
        }
        foreach ($summary['errors'] as $error) {
            $this->error(self::scrub($error, $settings));
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The knob's matching after a read (Bank transfer → «Match credits to invoices automatically»): null when it is off.
     *
     * @param  array{auto_match: bool, match_days: int}  $settings
     * @return array{matched: int, left: int}|null
     */
    public static function match(array $settings): ?array
    {
        if (!$settings['auto_match']) {
            PaymentMatcher::stop();

            return null;
        }

        return PaymentMatcher::run($settings['match_days']);
    }

    /** Never the login or the password in an error staff read. */
    private static function scrub(string $message, array $settings): string
    {
        return Redact::text(str_replace(array_filter([$settings['password'], $settings['username']]), '********', $message));
    }
}

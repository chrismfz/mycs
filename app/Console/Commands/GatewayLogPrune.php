<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayEvent;
use Illuminate\Console\Command;

/**
 * The gateway log is kept for good (docs/money-trail.md §6), except what no gateway sent: requests refused (4xx) that
 * moved no money and named no invoice, with no signature that held, after DAYS days. Forged or stray requests: anyone
 * can post to a webhook URL, and each one writes a row.
 */
class GatewayLogPrune extends Command
{
    public const DAYS = 90;

    protected $signature = 'mycs:gateway-log-prune {--days=' . self::DAYS . ' : Refused requests older than this many days}';

    protected $description = 'Delete gateway log rows of refused requests that moved no money (forged or stray), after 90 days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = PaymentGatewayEvent::query()
            ->where('outcome', PaymentGatewayEvent::OUTCOME_REJECTED)
            ->whereNull('amount')->whereNull('invoice_id')->whereNull('invoice_transaction_id')
            ->where(fn ($query) => $query->whereNull('signature_verified')->orWhere('signature_verified', false))
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
        $this->info("{$deleted} refused gateway request(s) older than {$days} days deleted.");

        return self::SUCCESS;
    }
}

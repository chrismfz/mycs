<?php

namespace MyCS\Extensions\Others\Affiliates\Listeners;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;

class RewardAffiliate
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        /**
         * @var Invoice $invoice
         */
        $invoice = $event->invoice;

        // Per line (PR C: one renewal invoice can bill several services, of different orders): each affiliate is rewarded
        // on the lines of the services it referred, not on the whole invoice.
        $referred = []; // affiliate id => [affiliate, cents of its lines]
        foreach ($invoice->items()->where('reference_type', Service::class)->get() as $line) {
            $order = $line->reference?->order; // a service deleted since: no order to reward
            $referral = $order ? AffiliateOrder::where('order_id', $order->id)->first() : null;
            /** @var Affiliate|null $affiliate */
            $affiliate = $referral?->affiliate;
            if (!$affiliate) {
                continue;
            }
            $referred[$affiliate->id] ??= [$affiliate, 0];
            $referred[$affiliate->id][1] += $line->totalCents();
        }
        if ($referred === []) {
            return;
        }

        $extension = ExtensionHelper::getExtension('other', 'Affiliates');
        foreach ($referred as [$affiliate, $lineCents]) {
            $reward_percentage = $affiliate->reward ?: $extension->config('default_reward');

            // The reward becomes the affiliate's account credit, through the ledger (docs/money-trail.md §2).
            $cents = (int) round($lineCents * $reward_percentage / 100);
            if ($cents > 0) {
                (new CreditLedger)->add((int) $affiliate->client_id, $invoice->currency_code, $cents,
                    MoneyTrail::CAUSE_AFFILIATE_REWARD, MoneyTrail::SOURCE_SYSTEM, $invoice);
            }
        }
    }
}

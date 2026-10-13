<?php

namespace Paymenter\Extensions\Others\Affiliates\Listeners;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;

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

        if ($invoice->items()->first()->reference_type !== Service::class) {
            return;
        }
        $order = $invoice->items()->first()->reference?->order; // a service deleted since: no order to reward
        if (!$order) {
            return;
        }
        $referral = AffiliateOrder::where('order_id', $order->id)->first();

        if (!$referral) {
            return;
        }

        /**
         * @var Affiliate $affiliate
         */
        $affiliate = $referral->affiliate;
        $extension = ExtensionHelper::getExtension('other', 'Affiliates');
        $reward_percentage = $affiliate->reward ?: $extension->config('default_reward');
        $reward_amount = $invoice->total * $reward_percentage / 100;

        // The reward becomes the affiliate's account credit, through the ledger (docs/money-trail.md §2).
        $cents = RecordPaymentService::toCents($reward_amount);
        if ($cents > 0) {
            (new CreditLedger)->add((int) $affiliate->user->getKey(), $invoice->currency_code, $cents,
                MoneyTrail::CAUSE_AFFILIATE_REWARD, MoneyTrail::SOURCE_SYSTEM, $invoice);
        }
    }
}

<?php

namespace MyCS\Extensions\Others\Affiliates\Listeners;

use App\Events\Order\Created;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cookie;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;

class AssociateOrderWithAffiliate
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
    public function handle(Created $event): void
    {
        // Prevents admin created orders from being associated with an affiliate
        if (Filament::getCurrentPanel()?->getId() === 'admin' || CurrentClient::id() !== (int) $event->order->client_id) {
            return;
        }

        $referral_code = Cookie::get('referred_by');

        /** @var Affiliate */
        $affiliate = Affiliate::where('code', $referral_code)->first();
        if (!$affiliate || $affiliate->client_id === $event->order->client_id) {
            return;
        }

        AffiliateOrder::create([
            'order_id' => $event->order->id,
            'affiliate_id' => $affiliate->id,
        ]);
    }
}

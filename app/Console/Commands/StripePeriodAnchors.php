<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Console\Command;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Throwable;

/**
 * The Stripe subscriptions that bill before their service's due day (anchored at local midnight, which Stripe's UTC
 * calendar moves to the day before): listed, and with --apply moved to local noon of the due day (trial_end, no
 * proration), as the next renewal would move them. Detect first; nothing is written without --apply.
 */
class StripePeriodAnchors extends Command
{
    protected $signature = 'stripe:period-anchors {--apply : Move each one listed to local noon of its due day at Stripe}';

    protected $description = 'List (and with --apply re-anchor) the Stripe subscriptions that bill before their service\'s due day';

    public function handle(): int
    {
        $gateway = Gateway::query()->where('extension', 'Stripe')->first();
        $stripe = $gateway !== null ? ExtensionHelper::getExtension('gateway', 'Stripe', $gateway->settings) : null;
        if (!$stripe instanceof Stripe) {
            $this->line('No Stripe gateway: nothing to check.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $early = $failed = 0;
        $services = Service::query()->whereNotNull('subscription_id')->whereNotNull('expires_at')
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->orderBy('id')->get();
        foreach ($services as $service) {
            if (!$stripe->ownsSubscription($service)) {
                continue;
            }
            $anchor = Stripe::anchorFor($service);
            try {
                $periodEnd = $stripe->currentPeriodEnd($service);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("#{$service->id} {$service->subscription_id}: not read ({$e->getMessage()})");

                continue;
            }
            if (Stripe::billsOnTime($periodEnd, $service)) {
                continue;
            }
            $early++;
            $line = "#{$service->id} {$service->subscription_id}: Stripe bills "
                . Carbon::createFromTimestamp($periodEnd, date_default_timezone_get())->format('Y-m-d H:i')
                . ', due ' . $service->expires_at->toDateString();
            if ($anchor->isPast()) {
                $this->warn($line . ': its due day has passed, left for staff');

                continue;
            }
            if (!$apply) {
                $this->line($line);

                continue;
            }
            try {
                $stripe->updateSubscription($service, ['expires_at' => $service->expires_at]);
                $this->info($line . ': moved to ' . $anchor->format('Y-m-d H:i'));
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error($line . ": not moved ({$e->getMessage()})");
            }
        }

        $this->line($early === 0 ? 'Every Stripe subscription bills on its due day.'
            : "{$early} bill(s) before the due day" . ($apply ? '.' : ': run with --apply to move them.'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\RwPay\RwPay;
use Tests\TestCase;

/**
 * Verification review of 483fea3f (round 3 of #411). The tests marked BUG fail on 483fea3f; the others are proofs that
 * pass (defaults unchanged, ADV-5 kept).
 */
class SuspensionWindowRound4Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MailFacade::fake();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(RwPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\RwPay; class RwPay {
                public static array $charges = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "at" => \Illuminate\Support\Carbon::today()->toDateString()];
                    throw new \App\Exceptions\DisplayException("Card declined: insufficient_funds");
                } }');
        }
        RwPay::$charges = [];
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'RwPay'], ['name' => 'RwPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_w4', 'type' => 'card']);
        $this->client->refresh();
        $this->client->forceFill(['auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function weekdaysFrom10(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
    }

    /** @return array{0: Service, 1: Invoice} */
    private function renewal(string $due): array
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return [$service, $invoice->fresh()];
    }

    private function chargeOn(string $day): void
    {
        Carbon::setTestNow("{$day} 00:00:05");
        (new AutoCharge)->run();
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /** @return list<string> */
    private function triedOn(): array
    {
        return array_column(RwPay::$charges, 'at');
    }

    /**
     * BUG (low, customer mail). With retry days set, a postponed suspension adds a try every day up to its morning (the
     * fix), but lastTry() still picks the last *retry day* ≤ the postponed day: the «last try» mail goes on day 1, and the
     * real last try, the morning of the Monday suspension, is silent. Without retry days it is right (lastTry = P).
     */
    public function test_with_retry_days_the_last_try_mail_goes_on_the_real_last_try_before_a_postponed_suspension(): void
    {
        $this->weekdaysFrom10();
        config(['settings.autocharge_retry_days' => '1']);
        $this->renewal('2026-10-21'); // Wednesday; Suspend Days 2: Saturday 24 → Monday 26 at 10:00

        $mailsBefore = [];
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            $mailsBefore[$day] = $this->failedMails();
            $this->chargeOn($day);
        }

        $this->assertSame(['2026-10-21', '2026-10-22', '2026-10-24', '2026-10-25', '2026-10-26'], $this->triedOn(), 'precondition: tried Wed, Thu (retry day 1), Sat, Sun, Mon');
        $this->assertSame(1, $this->failedMails() - $mailsBefore['2026-10-26'],
            'the last try before Monday\'s suspension (Mon 00:00) declined in silence; the «last try» mail went on Thursday, with three tries still to come');
    }

    /** Proof (passes): defaults, the window «always»: not charged the day after Suspend Days, as before (ADV-5). */
    public function test_defaults_the_card_is_not_tried_past_suspend_days(): void
    {
        $this->renewal('2026-10-21');
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            $this->chargeOn($day);
        }

        $this->assertSame(['2026-10-21', '2026-10-22', '2026-10-23'], $this->triedOn());
    }

    /**
     * Proof (passes): with a window, an invoice whose suspension is not postponed (Monday due, Thursday suspension) is not
     * charged in the 7 extra days the query now reads (ADV-5 kept per invoice).
     */
    public function test_window_an_invoice_whose_suspension_is_not_postponed_is_not_charged_in_the_wider_query(): void
    {
        $this->weekdaysFrom10();
        $this->renewal('2026-10-19'); // Monday; suspension Thursday 22 (a weekday): not postponed
        foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            $this->chargeOn($day);
        }

        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21'], $this->triedOn());
    }

    /** Proof (passes): defaults, staff's Unsuspend of a service the cron suspended adds no hold. */
    public function test_defaults_an_unsuspend_adds_no_hold(): void
    {
        [$service] = $this->renewal('2026-10-01');
        $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertNull($service->fresh()->suspend_hold_until);
    }

    /** Proof (passes): with a window, a staff hold later than today is never shortened by the automatic one. */
    public function test_window_an_unsuspend_keeps_a_later_staff_hold(): void
    {
        $this->weekdaysFrom10();
        Carbon::setTestNow('2026-10-26 10:30:00');
        [$service] = $this->renewal('2026-10-01');
        $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'suspend_hold_until' => '2026-11-05']);

        $this->assertSame('2026-11-05', $service->fresh()->suspend_hold_until->toDateString());
    }

    /**
     * BUG (low, amplified pre-existing). The automatic hold is today's date; «Do Not Suspend Until» has minDate(now()). From
     * the next day the service's edit page cannot be saved (any field) until staff clear a hold they never set. With a
     * window, every unsuspend after a dunning suspension (staff's, or a payment's) leaves one.
     */
    public function test_window_the_service_page_still_saves_the_day_after_an_unsuspend(): void
    {
        $this->weekdaysFrom10();
        Carbon::setTestNow('2026-10-26 10:30:00');
        [$service] = $this->renewal('2026-10-01');
        $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame('2026-10-26', $service->fresh()->suspend_hold_until?->toDateString(), 'precondition: the automatic hold');

        Carbon::setTestNow('2026-10-27 09:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R4' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->call('save')->assertHasNoErrors();
    }
}

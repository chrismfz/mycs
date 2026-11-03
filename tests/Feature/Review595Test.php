<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Review of #595: the «service changed» mail. */
class Review595Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.mail_disable' => false]);
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
    }

    /** @return array{0: Service, 1: ServiceUpgrade} */
    private function upgradeOf(array $service = [], string $planType = 'recurring'): array
    {
        $from = $this->createProduct();
        $from->product->update(['name' => 'Hosting 10']);
        $from->plan->update(['type' => $planType]);
        $to = Product::factory()->create(['name' => 'Hosting 20', 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $to->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => $planType]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'currency_code' => 'USD']);
        $model = Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id, 'product_id' => $from->product->id,
            'plan_id' => $from->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'expires_at' => now()->addMonth(), 'domain' => 'shop.example.gr'], $service));
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $model->id, 'product_id' => $to->id, 'plan_id' => $plan->id,
            'status' => ServiceUpgrade::STATUS_PENDING]);

        return [$model, $upgrade];
    }

    /** A one-time (or free) plan has no expiry: the mail prints «next renewal on  it renews at». */
    public function test_a_service_without_an_expiry_gets_no_empty_renewal_date(): void
    {
        [$service, $upgrade] = $this->upgradeOf(['expires_at' => null], 'one-time');

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $body = (string) EmailLog::query()->where('subject', 'like', 'Service changed:%')->sole()->body;
        $this->assertStringNotContainsString('<strong></strong>', $body, 'an empty renewal date in the mail');
        $this->assertStringNotContainsString('renews at', $body, 'a one-time service never renews');
    }

    /** Direct: a Free Account (recurring, no due date, price 0). */
    public function test_a_free_account_mail_has_no_empty_date(): void
    {
        [$service] = $this->upgradeOf(['expires_at' => null, 'price' => 0]);
        $service = $service->fresh(['product', 'client']);

        NotificationHelper::serviceUpgradedNotification($service->client, $service, 'Hosting 5');

        $body = (string) EmailLog::query()->where('subject', 'like', 'Service changed:%')->sole()->body;
        $this->assertStringNotContainsString('<strong></strong>', $body);
    }

    public function test_applied_twice_one_mail(): void
    {
        [, $upgrade] = $this->upgradeOf();

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));
        $this->assertFalse((new ServiceUpgradeService)->handle(ServiceUpgrade::query()->find($upgrade->id)));

        $this->assertSame(1, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
    }

    public function test_rolled_back_no_mail(): void
    {
        [, $upgrade] = $this->upgradeOf();

        DB::beginTransaction();
        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));
        DB::rollBack();

        $this->assertSame(0, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
    }

    public function test_cancelled_service_no_mail(): void
    {
        [, $upgrade] = $this->upgradeOf(['status' => 'cancelled']);

        $this->assertFalse((new ServiceUpgradeService)->handle($upgrade));

        $this->assertSame(0, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
    }

    public function test_greek_account_gets_greek(): void
    {
        [$service, $upgrade] = $this->upgradeOf();
        $service->client->forceFill(['language' => 'el'])->save();

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $log = EmailLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        fwrite(STDERR, "\nSUBJECT: {$log->subject}\nBODY: " . substr(strip_tags((string) $log->body), 0, 900) . "\n");
    }
}

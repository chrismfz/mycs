<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two gaps of the owner's live lifecycle test on orion (2026-11-03): WHM showed «Unknown» as the suspension's reason
 * (WHMCS sends its own), and an upgrade or downgrade applied told the customer nothing (WHMCS confirms one).
 */
class SuspendReasonAndUpgradeMailTest extends TestCase
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

    public function test_the_suspension_tells_whm_its_reason(): void
    {
        Http::fake(fn () => Http::response(['metadata' => ['result' => 1]]));
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.example.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'server_id' => $server->id, 'status' => 'suspended', 'suspend_reason' => 'Overdue on Payment',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $service->properties()->create(['key' => 'cpanel_username', 'value' => 'alice']);

        ExtensionHelper::suspendServer($service->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/suspendacct')
            && str_contains($request->url(), 'user=alice') && str_contains(urldecode($request->url()), 'reason=Overdue on Payment'));
    }

    public function test_an_applied_upgrade_tells_the_customer_from_what_to_what(): void
    {
        $from = $this->createProduct();
        $from->product->update(['name' => 'Hosting 10']);
        $to = Product::factory()->create(['name' => 'Hosting 20', 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $to->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $from->product->id,
            'plan_id' => $from->plan->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'expires_at' => now()->addMonth(), 'domain' => 'shop.example.gr']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $to->id, 'plan_id' => $plan->id,
            'status' => ServiceUpgrade::STATUS_PENDING]);

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $mail = EmailLog::query()->where('subject', 'like', 'Service changed:%')->sole();
        $this->assertSame('Service changed: Hosting 20 — shop.example.gr', $mail->subject);
        $this->assertStringContainsString('from <strong>Hosting 10</strong> to <strong>Hosting 20</strong>', (string) $mail->body);
    }
}

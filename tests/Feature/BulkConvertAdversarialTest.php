<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\EmailLog;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Licensing\BulkConvert;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #850 (standalone licenses moved into a bulk one, docs/zonecloud-bulk-license.md §8.1).
 * Each test is a finding and fails on the PR's code; the setup mirrors BulkConvertTest.
 */
class BulkConvertAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Product $standalone;

    private Service $bulk;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $this->server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $this->clientId = User::factory()->create()->client()->id;
        $this->standalone = $this->product(bulk: false);
        $this->bulk = $this->service($this->product(bulk: true), now()->addMonths(2));
        EmailLog::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(bool $bulk, string $secret = 'ZoneCloudAgent'): Product
    {
        $created = $this->createProduct();
        $product = $created->product;
        $product->update(['server_id' => $this->server->id]);
        $created->plan->prices()->update(['price' => '1.80']);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => $secret, 'bulk' => $bulk ? '1' : '0', 'tiers' => '1=1.80, 51=1.50'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => $key === 'bulk' ? 'boolean' : 'string',
                'settingable_id' => $product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }

        return $product;
    }

    private function service(Product $product, Carbon $expires, ?string $ip = null, ?int $clientId = null): Service
    {
        $service = Service::factory()->create(['client_id' => $clientId ?? $this->clientId, 'plan_id' => $product->plans()->first()->id, 'product_id' => $product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => '1.80', 'quantity' => 1, 'expires_at' => $expires]);
        ExtensionHelper::createServer($service);
        if ($ip !== null) {
            $service->license->update(['valid_ip' => $ip, 'valid_domain' => $ip]);
        }

        return $service->fresh(['license', 'product.settings']);
    }

    private function check(string $key, string $ip): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/zlic.php', ['license' => $key, 'license_PSK' => 'ZoneCloudAgent'])->getContent(), true);
    }

    private function cron(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job');
    }

    private function mails(): array
    {
        return EmailLog::query()->pluck('template_key')->all();
    }

    /**
     * ADV-850-1. A license moving into a bulk one is terminated for another reason before its expiry (staff's Terminate,
     * or the overdue run): BulkConvert::join rightly does not join it, but TerminateJob asks only silent(), which is
     * true for any license with a bulk_license_id, so the customer's «terminated» mail is dropped too.
     * Expected: the server_terminated mail, as for any license ended that way (the move is not what ended it).
     * Actual: no mail; the customer's license just stops.
     */
    public function test_adv_850_1_a_moving_license_terminated_for_another_reason_still_mails_the_customer(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        EmailLog::query()->delete();

        $old->update(['status' => Service::STATUS_TERMINATED]);
        (new TerminateJob($old->fresh()))->handle();

        $this->assertNull($old->license->fresh()->merged_at, 'it did not join (as designed)');
        $this->assertContains('server_terminated', $this->mails(), 'ended for another reason: the customer is told, as for any license');
    }

    /**
     * ADV-850-2. The bulk license ends before the moving license's expiry (its customer's own request, non-payment).
     * At the expiry the move's cancellation ends the old license, join() skips it («else it ends as any license», §8.1),
     * but silent() still drops the «cancelled» mail: a license whose renewal staff stopped dies with no word to anyone.
     * Expected: the service_cancelled mail (it ends as any license does), or the move refused/withdrawn.
     * Actual: Expired, no mail.
     */
    public function test_adv_850_2_a_move_into_a_bulk_that_ended_meanwhile_is_not_silent(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $this->bulk->update(['status' => Service::STATUS_CANCELLED]);
        EmailLog::query()->delete();

        $this->cron('2026-11-20 10:00:00');

        $this->assertSame('Expired', $this->check($old->license->license_key, '203.0.113.10')['status']);
        $this->assertContains('service_cancelled', $this->mails(), 'it did not join: it ended as any license, and the customer hears of it');
    }

    /**
     * ADV-850-3. After the move, staff move the BULK service to another client (MoveToClient::service checks only the
     * moved service's own cancellation, not the licenses joining it). At the old license's expiry join() reads the bulk
     * by id and adds client A's IP to client B's list: B is billed for A's server, and A's key answers as B's license
     * (B's serviceid, B's name in the tags).
     * Expected: the bulk's move refused while licenses are joining it, or join() refusing a bulk of another client.
     * Actual: A's IP on B's list, A's key answering as B's service.
     */
    public function test_adv_850_3_a_bulk_moved_to_another_client_does_not_take_in_the_first_clients_license(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $other = User::factory()->create()->client();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        try {
            (new MoveToClient)->service($this->bulk, $other, $staff);
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused: fine');

            return;
        }
        $this->assertSame($other->id, $this->bulk->fresh()->client_id);

        $this->cron('2026-11-20 10:00:00');

        $this->assertSame(0, LicenseIp::query()->where('license_id', $this->bulk->license->id)->where('ip', '203.0.113.10')->count(),
            "client A's IP is not added to client B's bulk list");
        $this->assertNotSame((string) $this->bulk->id, $this->check($old->license->license_key, '203.0.113.10')['serviceid'] ?? null,
            "client A's key does not answer as client B's license");
    }

    /**
     * ADV-850-4. Staff move licenses into a bulk license that already has a cancellation request at the end of its
     * period (convert checks only its status). The move stops each license's renewal; the bulk ends first; at each
     * license's expiry join() finds no running bulk and the license just ends: the customer loses licenses that were
     * renewing, for a bulk that was known to be ending.
     * Expected: convert refused (DisplayException) for a bulk with a cancellation request.
     * Actual: accepted.
     */
    public function test_adv_850_4_no_move_into_a_bulk_license_that_is_cancelling(): void
    {
        ServiceCancellation::query()->create(['service_id' => $this->bulk->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $old = $this->service($this->standalone, now()->addMonths(3), '203.0.113.10');

        $this->expectException(DisplayException::class);
        BulkConvert::convert($this->bulk, [$old->id]);
    }

    /**
     * ADV-850-5. A suspended standalone license is offered and moved (candidates take STATUS_SUSPENDED, and only an
     * Expired license row is excluded). At its expiry it joins, and its key answers as the bulk license: Active. A
     * suspension (staff's for abuse, or non-payment: its unpaid renewal is cancelled by the move's request) is lifted
     * by the move, with nothing paid for it. The form says «Running, renewing licenses».
     * Expected: a suspended one not offered (or its key still Suspended after the join).
     * Actual: offered, moved, and its key answers Active from its expiry.
     */
    public function test_adv_850_5_a_suspended_license_is_not_revived_by_the_move(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        $old->update(['status' => Service::STATUS_SUSPENDED]);
        $old->license->update(['status' => License::STATUS_SUSPENDED]);
        $this->assertSame('Suspended', $this->check($old->license->license_key, '203.0.113.10')['status']);

        try {
            BulkConvert::convert($this->bulk, [$old->id]);
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused: fine');

            return;
        }
        $this->cron('2026-11-20 10:00:00');

        $this->assertNotSame('Active', $this->check($old->license->license_key, '203.0.113.10')['status'],
            'a suspended license is not made Active by moving it into a bulk one');
    }
}

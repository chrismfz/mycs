<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\EmailLog;
use App\Models\Invoice;
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
use App\Services\Licensing\BulkConvert;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Licensing\Licenses;
use App\Services\Service\DeleteService;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Standalone licenses moved into a bulk one (docs/zonecloud-bulk-license.md §8.1, PR 7): each keeps working as it is
 * until its expiry, then its IP joins the bulk list that day and its key answers as the bulk license. Its renewal stops;
 * no mail to the customer (owner 2026-11-13).
 */
class BulkConvertTest extends TestCase
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
        EmailLog::query()->delete(); // the sign-up's verification mail: only what follows counts
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

    /** A running service of $product with its license made, bound to $ip if given. */
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

    public function test_only_running_standalone_licenses_of_the_client_with_the_same_secret_are_offered(): void
    {
        $one = $this->service($this->standalone, now()->addDays(7), '203.0.113.10');
        $this->service($this->standalone, now()->addDays(7), '203.0.113.11', User::factory()->create()->client()->id);  // another client's
        $this->service($this->product(bulk: false, secret: 'ZoneCloudNode'), now()->addDays(7), '203.0.113.12');      // another secret
        $cancelling = $this->service($this->standalone, now()->addDays(7), '203.0.113.13');
        ServiceCancellation::query()->create(['service_id' => $cancelling->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $ended = $this->service($this->standalone, now()->addDays(7), '203.0.113.14');
        $ended->update(['status' => Service::STATUS_CANCELLED]);
        $this->service($this->bulk->product, now()->addDays(7));                                                       // another bulk
        $this->service($this->standalone, now()->addDays(7), '203.0.113.15')->update(['status' => Service::STATUS_SUSPENDED]);
        $this->service($this->standalone, now()->addDays(7), '203.0.113.16')->license->update(['status' => License::STATUS_REISSUED]);
        $conflict = $this->product(bulk: false);
        (new Setting)->forceFill(['key' => 'allow_ip_conflict', 'value' => '1', 'type' => 'boolean', 'settingable_id' => $conflict->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        $this->service($conflict, now()->addDays(7), '203.0.113.17');
        $owing = $this->service($this->standalone, now()->addDays(7), '203.0.113.18');
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $owing->id, 'description' => 'Renewal', 'price' => '1.80', 'quantity' => 1]);
        $withAddon = $this->service($this->standalone, now()->addDays(7), '203.0.113.19');
        $this->service($this->standalone, now()->addDays(7))->update(['parent_service_id' => $withAddon->id]);

        $this->assertSame([$one->id], BulkConvert::candidates($this->bulk)->pluck('id')->all(),
            'not: suspended, waiting for a reissue, «Allow IP conflict», owing, with an addon (review of #850: cut, staff by hand)');
        $this->expectException(DisplayException::class);
        BulkConvert::convert($this->bulk, [$one->id, $cancelling->id]);
    }

    public function test_it_keeps_working_until_its_expiry_then_its_ip_joins_and_its_key_answers_as_the_bulk_license(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        $key = $old->license->license_key;

        $this->assertSame(1, BulkConvert::convert($this->bulk, [$old->id]));

        $this->assertSame($this->bulk->license->id, $old->license->fresh()->bulk_license_id);
        $this->assertSame('end_of_period', $old->cancellation()->value('type'));
        $this->assertSame([], EmailLog::query()->pluck('template_key')->all(), 'no mail for the move (owner 2026-11-13)');
        $before = $this->check($key, '203.0.113.10');
        $this->assertSame(['Active', (string) $old->id], [$before['status'], $before['serviceid']], 'until its expiry, as it is');

        $this->cron('2026-11-13 10:00:00');
        $this->cron('2026-11-17 10:00:00');
        $this->assertSame(0, Invoice::query()->count(), 'its renewal is not billed');

        $this->cron('2026-11-20 10:00:00');

        $this->assertSame(Service::STATUS_CANCELLED, $old->fresh()->status);
        $license = $old->license->fresh();
        $this->assertSame([License::STATUS_EXPIRED, '2026-11-20'], [$license->status, $license->merged_at?->toDateString()]);
        $row = LicenseIp::query()->where('license_id', $this->bulk->license->id)->sole();
        $this->assertSame(['203.0.113.10', 'service #' . $old->id, '2026-11-20'], [$row->ip, $row->label, $row->added_at->toDateString()]);
        $after = $this->check($key, '203.0.113.10');
        $this->assertSame(['Active', (string) $this->bulk->id, '203.0.113.10'], [$after['status'], $after['serviceid'], $after['validip']],
            'the old key answers as the bulk license (Ε7)');
        $this->assertArrayHasKey('localkey', $after);
        $this->assertNotNull($row->fresh()->last_ok_at, 'its good check is counted on the bulk list');
        $this->assertSame('IP Address Invalid', $this->check($key, '203.0.113.99')['message'] ?? null, 'only listed IPs, as the bulk key');
        $this->assertSame([], EmailLog::query()->pluck('template_key')->all(), 'no «cancelled» mail at its end either');
    }

    public function test_one_never_bound_joins_with_no_ip(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'));
        BulkConvert::convert($this->bulk, [$old->id]);

        $this->cron('2026-11-20 10:00:00');

        $this->assertNotNull($old->license->fresh()->merged_at);
        $this->assertSame(0, LicenseIp::query()->count());
        $this->assertSame('IP Address Invalid', $this->check($old->license->license_key, '203.0.113.10')['message'] ?? null,
            'no server is bound to it: one added to the list is answered');
        BulkLicenseIps::add($this->bulk->license, '203.0.113.10');
        $this->assertSame('Active', $this->check($old->license->license_key, '203.0.113.10')['status']);
    }

    public function test_withdrawn_before_its_expiry_it_renews_as_a_standalone_license_again(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);

        $old->cancellation()->get()->each->delete();

        $this->assertNull($old->license->fresh()->bulk_license_id);
        $this->assertSame([$old->id], BulkConvert::candidates($this->bulk)->pluck('id')->all());
        $this->cron('2026-11-20 10:00:00');
        $this->assertSame(Service::STATUS_ACTIVE, $old->fresh()->status);
        $this->assertSame(0, LicenseIp::query()->count());
    }

    public function test_into_a_bulk_license_that_ended_meanwhile_it_just_ends(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $this->bulk->update(['status' => Service::STATUS_CANCELLED]);

        $this->cron('2026-11-20 10:00:00');

        $this->assertNull($old->license->fresh()->merged_at);
        $this->assertSame(License::STATUS_EXPIRED, $old->license->fresh()->status);
        $this->assertSame('Expired', $this->check($old->license->license_key, '203.0.113.10')['status']);
        $this->assertSame(['service_cancelled'], EmailLog::query()->pluck('template_key')->all(), 'not joined: told as any (review of #850)');
        $this->assertSame('Was to join the bulk license of service #' . $this->bulk->id . ', but ended without joining.',
            (new \ReflectionMethod(ServiceResource::class, 'bulkMove'))->invoke(null, $old->fresh()));
    }

    public function test_terminated_by_staff_while_moving_it_is_told_as_any_and_does_not_join(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $old->update(['status' => Service::STATUS_TERMINATED]);

        dispatch_sync(new TerminateJob($old->fresh()));

        $this->assertNull($old->license->fresh()->merged_at);
        $this->assertSame(['server_terminated'], EmailLog::query()->pluck('template_key')->all(), 'review of #850');
    }

    public function test_a_service_whose_license_joined_cannot_be_deleted(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $this->cron('2026-11-20 10:00:00');

        try {
            (new DeleteService)->handle($old->fresh());
            $this->fail('refused');
        } catch (DisplayException) {
        }
        $this->assertSame('Active', $this->check($old->license->license_key, '203.0.113.10')['status'], 'its key still answers (review of #850)');
    }

    public function test_ended_for_another_reason_it_does_not_join(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $old->update(['status' => Service::STATUS_TERMINATED]);

        BulkConvert::join($old);

        $this->assertNull($old->license->fresh()->merged_at);
        $this->assertSame(0, LicenseIp::query()->count());
    }

    public function test_its_ip_on_another_clients_bulk_list_fails_the_join_for_staff_and_a_retry_joins_it(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $other = $this->service($this->bulk->product, now()->addMonths(2), clientId: User::factory()->create()->client()->id);
        BulkLicenseIps::add($other->license, '203.0.113.10');
        $old->update(['status' => Service::STATUS_CANCELLED]);

        try {
            BulkConvert::join($old->fresh());
            $this->fail('refused');
        } catch (DisplayException) {
        }
        $this->assertNull($old->license->fresh()->merged_at, 'not joined: its module action fails and stays for staff');

        BulkLicenseIps::remove($other->license, $other->license->ips()->pluck('id')->all());
        BulkConvert::join($old->fresh());
        BulkConvert::join($old->fresh());

        $this->assertNotNull($old->license->fresh()->merged_at);
        $this->assertSame(1, LicenseIp::query()->where('license_id', $this->bulk->license->id)->count(), 'a second run adds nothing');
    }

    public function test_the_preview_counts_new_ips_and_the_cost_once_all_are_in(): void
    {
        BulkLicenseIps::add($this->bulk->license, '203.0.113.10');
        $a = $this->service($this->standalone, Carbon::parse('2026-11-20'), '203.0.113.10');  // already listed
        $b = $this->service($this->standalone, Carbon::parse('2026-12-02'), '203.0.113.11');
        $c = $this->service($this->standalone, Carbon::parse('2026-11-25'));

        $preview = BulkConvert::preview($this->bulk, [$a->id, $b->id, $c->id]);

        $this->assertSame(['licenses' => 3, 'ips' => 1, 'without' => 1, 'from' => '20/11/2026', 'to' => '02/12/2026', 'monthly' => '3.60'], $preview);
    }

    public function test_staff_move_licenses_in_from_the_bulk_service_and_undo_one_from_its_own(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $a = $this->service($this->standalone, Carbon::parse('2026-11-20'), '203.0.113.10');
        $b = $this->service($this->standalone, Carbon::parse('2026-11-25'), '203.0.113.11');

        $this->livewireAs($staff)->test(EditService::class, ['record' => $a->getRouteKey()])->assertActionHidden('moveLicensesIn')->assertActionHidden('undoMoveToBulk');
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->bulk->getRouteKey()])
            ->callAction('moveLicensesIn', ['ids' => [$a->id, $b->id]])
            ->assertHasNoActionErrors()
            ->assertSee('2 joining (next 20/11/2026), 0 joined');
        $this->assertSame([$this->bulk->license->id, $this->bulk->license->id], [$a->license->fresh()->bulk_license_id, $b->license->fresh()->bulk_license_id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $b->getRouteKey()])
            ->assertSee('Joins the bulk license of service #' . $this->bulk->id . ' at its expiry, 25/11/2026.')
            ->callAction('undoMoveToBulk')
            ->assertHasNoActionErrors();
        $this->assertNull($b->license->fresh()->bulk_license_id);
        $this->assertNull($b->fresh()->cancellation);
        $this->assertSame([$b->id], BulkConvert::candidates($this->bulk)->pluck('id')->all());
    }

    public function test_a_license_moving_into_bulk_is_not_reissued_its_binding_is_what_joins(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);

        $this->assertFalse(Licenses::canReissue($old->license->fresh()->setRelation('service', $old)));
        $this->expectException(DisplayException::class);
        Licenses::reissue($old->license->fresh(), '203.0.113.10', byStaff: true);
    }

    public function test_it_joins_in_the_crons_own_run_its_key_never_answers_expired_while_the_terminate_is_queued(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        Queue::fake();

        $this->cron('2026-11-20 10:00:00');

        Queue::assertPushed(TerminateJob::class);
        $this->assertNotNull($old->license->fresh()->merged_at, 'joined by the cron, not by the worker (review of #850)');
        $this->assertSame(['Active', (string) $this->bulk->id], array_values(array_intersect_key(
            $this->check($old->license->license_key, '203.0.113.10'), ['status' => 1, 'serviceid' => 1])));
    }

    public function test_a_join_refused_at_its_expiry_leaves_it_running_as_it_is_for_staff(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);
        $other = $this->service($this->bulk->product, now()->addMonths(2), clientId: User::factory()->create()->client()->id);
        BulkLicenseIps::add($other->license, '203.0.113.10');

        try {
            $this->cron('2026-11-20 10:00:00');
            $this->fail('the run fails and says why (staff get «Cron Job Error»)');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('203.0.113.10', $e->getMessage());
        }

        $this->assertSame(Service::STATUS_ACTIVE, $old->fresh()->status, 'nothing ended');
        $this->assertSame('Active', $this->check($old->license->license_key, '203.0.113.10')['status'], 'its servers keep working');

        // Days past its Suspend Days, still refused: never suspended as unpaid (owner 2026-11-13).
        try {
            $this->cron('2026-12-05 10:00:00');
        } catch (DisplayException) {
        }
        $this->assertSame(Service::STATUS_ACTIVE, $old->fresh()->status, 'not suspended while it waits to join');
        $this->assertSame('Active', $this->check($old->license->license_key, '203.0.113.10')['status']);
    }

    public function test_a_bulk_service_taking_licenses_in_cannot_be_deleted(): void
    {
        $old = $this->service($this->standalone, Carbon::parse('2026-11-20 00:00:00'), '203.0.113.10');
        BulkConvert::convert($this->bulk, [$old->id]);

        $this->expectException(DisplayException::class);
        (new DeleteService)->handle($this->bulk->fresh());
    }
}

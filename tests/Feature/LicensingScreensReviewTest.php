<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
use App\Models\License;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Adversarial review of L2 (a2e3d56): the license screens. */
class LicensingScreensReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $service;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true],
            'allow_reissue' => ['1', false], 'custom_fields' => ['comments', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $this->owner = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'server_id' => $server->id]);
        ExtensionHelper::createServer($this->service);
        $this->license = License::query()->sole();
        $this->license->update(['valid_ip' => '198.51.100.7']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    // ---- Findings (fail on a2e3d56) ----

    /**
     * R1: «Reissue license» is visible for every license, but Licenses::reissue(byStaff) throws a DisplayException for a
     * Suspended or Expired one, and the Filament action does not catch it (EditService has no DisplayException handler,
     * unlike the customer's Show): staff get a server error instead of a notification.
     */
    public function test_r1_staff_reissue_of_a_suspended_license_is_refused_with_a_notification_not_an_exception(): void
    {
        $this->license->update(['status' => License::STATUS_SUSPENDED]);
        $staff = $this->staff();

        // The fix: not offered on a suspended or expired license (and refused with a notification if called anyway).
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertActionHidden('reissueLicense');

        $this->assertSame(License::STATUS_SUSPENDED, $this->license->fresh()->status);
    }

    /**
     * R2: the licenses console eager-loads service.client but not what Client::name reads (primaryContact,
     * ownerGrant.user), so every row costs extra queries (ServiceResource loads them for the same column).
     */
    public function test_r2_the_console_does_not_query_per_row(): void
    {
        $staff = $this->staff();
        $count = function () use ($staff): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->livewireAs($staff)->test(ListLicenses::class);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm the per-request caches (role, currencies)
        $one = $count();
        for ($i = 0; $i < 5; $i++) {
            $user = User::factory()->create();
            $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
                'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1]);
            License::query()->create(['service_id' => $service->id, 'license_key' => 'ZCAgent-' . str_pad((string) $i, 16, '0'), 'status' => License::STATUS_ACTIVE, 'reissues' => 0]);
        }

        $this->assertSame($one, $count(), 'queries grow with the rows (N+1 on service.client.name)');
    }

    /**
     * R3: the customer's refused reissue shows Licenses' DisplayException message, «This license cannot be reissued.»,
     * which has no Greek translation (LocalizationTest does not scan app/Services, so it passes).
     */
    public function test_r3_the_refused_reissue_message_is_translated_to_greek(): void
    {
        $greek = json_decode(file_get_contents(base_path('lang/el.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('This license cannot be reissued.', $greek);
    }

    /**
     * R4: «Edit license» caps Valid IPs at 255 characters while the column is text (WHMCS's validip is text, a list of
     * IPs): for a license with a longer list staff cannot even change its status, the form fails on the untouched field.
     */
    public function test_r4_edit_license_saves_the_status_of_a_license_with_a_long_ip_list(): void
    {
        $ips = implode(',', array_map(fn ($i) => "198.51.100.$i", range(1, 25))); // 25 IPs, > 255 chars
        $this->assertGreaterThan(255, strlen($ips));
        $this->license->update(['valid_ip' => $ips]);
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('editLicense', data: ['status' => License::STATUS_SUSPENDED])
            ->assertHasNoActionErrors();

        $this->assertSame([License::STATUS_SUSPENDED, $ips], [$this->license->fresh()->status, $this->license->fresh()->valid_ip]);
    }

    // ---- Probes that hold (pass on a2e3d56) ----

    public function test_probe_a_login_with_only_view_permission_cannot_reissue_by_a_crafted_call(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $this->actingAs($viewer)->withSession($this->loginUser($viewer, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        Livewire::test(Show::class, ['service' => $this->service])->call('reissueLicense')->assertNotFound(); // the app's denials answer 404
        $this->assertSame(License::STATUS_ACTIVE, $this->license->fresh()->status);
    }

    public function test_probe_another_accounts_login_cannot_reissue(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->withSession($this->loginUser($stranger, [CurrentClient::SESSION_KEY => $stranger->client()->id]));

        Livewire::test(Show::class, ['service' => $this->service])->call('reissueLicense')->assertNotFound(); // the app's denials answer 404
        $this->assertSame(License::STATUS_ACTIVE, $this->license->fresh()->status);
    }

    public function test_probe_the_customer_reissues_once_then_not_again_nor_a_suspended_one(): void
    {
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->call('reissueLicense')->call('reissueLicense')->call('reissueLicense');
        $this->assertSame(1, $this->license->fresh()->reissues);

        $this->license->update(['status' => License::STATUS_SUSPENDED]);
        Livewire::test(Show::class, ['service' => $this->service])->call('reissueLicense');
        $this->assertSame([License::STATUS_SUSPENDED, 1], [$this->license->fresh()->status, $this->license->fresh()->reissues]);
    }

    public function test_probe_staff_without_update_cannot_reissue_or_edit_and_console_needs_view_any(): void
    {
        $viewer = $this->staff(['admin.services.viewAny']);
        $this->actingAs($viewer, 'staff')->get(EditService::getUrl(['record' => $this->service]))->assertForbidden();
        $this->actingAs($viewer, 'staff')->get(ListLicenses::getUrl())->assertOk();
    }

    public function test_probe_saving_edit_service_leaves_the_license_alone_and_works_without_one(): void
    {
        $staff = $this->staff();
        $before = $this->license->fresh()->getAttributes();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('reissueLicense')
            ->call('save')->assertHasNoErrors();
        $after = $this->license->fresh();
        $this->assertSame([License::STATUS_REISSUED, null, $before['license_key']], [$after->status, $after->valid_ip, $after->license_key]);

        $this->license->delete();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->call('save')->assertHasNoErrors();
    }

    public function test_probe_the_customer_sees_no_psk_nor_failure_nor_unreported_properties(): void
    {
        $this->license->update(['last_failure' => 'Invalid PSK secret-marker', 'last_failure_at' => now()]);
        $this->service->properties()->create(['key' => 'comments', 'name' => 'comments', 'value' => '<script>x</script>']);
        $this->service->properties()->create(['key' => 'internal_note', 'name' => 'internal', 'value' => 'staff-only-marker']);
        $html = $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]))
            ->get(route('services.show', $this->service))->assertOk()->getContent();

        $this->assertStringNotContainsString('TestSecret', $html);
        $this->assertStringNotContainsString('secret-marker', $html);
        $this->assertStringNotContainsString('staff-only-marker', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
    }

    public function test_probe_edit_license_is_audited_with_the_staff_member(): void
    {
        config(['audit.console' => true]); // tests run in the console, where auditing is off
        $staff = $this->staff();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('editLicense', data: ['status' => License::STATUS_SUSPENDED, 'valid_ip' => '203.0.113.9']);

        $audit = Audit::query()->where('auditable_type', $this->license->getMorphClass())->where('auditable_id', $this->license->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($staff->getMorphClass(), $audit->user_type);
        $this->assertSame(['status' => License::STATUS_SUSPENDED, 'valid_ip' => '203.0.113.9'], array_intersect_key($audit->new_values, ['status' => 1, 'valid_ip' => 1]));
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource;
use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\LicenseAccessLogsRelationManager;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\Licenses;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L2 (docs/licensing.md): the license on the customer's service page (key, valid IPs, status, the reported fields,
 * Reissue) and on staff's (signs of life, Reissue whatever the limit, edit bindings, the access log), and the console.
 */
class LicensingScreensTest extends TestCase
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
        $this->service->properties()->create(['key' => 'comments', 'name' => 'comments', 'value' => 'saf.example.test']);
    }

    private function page(User $user)
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->service->client_id]))
            ->get(route('services.show', $this->service))->assertOk()->getContent();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_the_customer_sees_the_license_and_reissues_it(): void
    {
        $html = $this->page($this->owner);
        $this->assertStringContainsString($this->license->license_key, $html);
        $this->assertStringContainsString('198.51.100.7', $html);
        $this->assertStringContainsString('saf.example.test', $html, 'the reported custom field');
        $this->assertStringContainsString(__('services.license.reissue'), $html);
        $this->assertSame(1, substr_count($html, $this->license->license_key . '</'), 'the key once, not also as «Domain»');

        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        Livewire::test(Show::class, ['service' => $this->service])->call('reissueLicense');

        $this->license->refresh();
        $this->assertSame([License::STATUS_REISSUED, null, 1], [$this->license->status, $this->license->valid_ip, $this->license->reissues]);
    }

    public function test_the_customer_sees_the_last_check_and_a_failure_newer_than_it(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->license->update(['last_failure_at' => now()->subHour(), 'last_failure' => 'IP Address Invalid (203.0.113.9)']);
        $this->assertStringContainsString(__('services.license.failure_ip'), $this->page($this->owner), 'never a good check, one failure');
        $this->license->update(['last_failure_at' => null]);
        $this->assertStringContainsString(__('services.license.never_checked'), $this->page($this->owner));

        $this->license->update(['last_ok_at' => now()->subMinutes(5), 'last_failure_at' => now()->subHour(), 'last_failure' => 'IP Address Invalid (203.0.113.9)']);
        $html = $this->page($this->owner);
        $this->assertStringContainsString(now()->subMinutes(5)->diffForHumans(), $html);
        $this->assertStringNotContainsString(__('services.license.failure_ip'), $html, 'an older failure the next good check put right');

        $this->license->update(['last_failure_at' => now()->subMinute()]);
        $html = $this->page($this->owner);
        $this->assertStringContainsString(__('services.license.failure_ip'), $html, 'the reason, not the recorded text');
        $this->assertStringNotContainsString('203.0.113.9', $html);

        $this->license->update(['last_failure' => 'MD5 Checksum Verification Failed (198.51.100.7)']);
        $this->assertStringContainsString(__('services.license.failure_other'), $this->page($this->owner));

        $this->license->update(['last_failure_at' => now()->subMinutes(5)]);
        $this->assertStringNotContainsString(__('services.license.failure_other'), $this->page($this->owner), 'the same moment as the good check');

        $this->license->update(['last_failure_at' => now()->subMinute(), 'status' => License::STATUS_REISSUED]);
        $this->assertStringNotContainsString(__('services.license.failure_other'), $this->page($this->owner), 'reissued: the next check binds it');

        app()->setLocale('el');
        $this->assertStringContainsString(__('services.license.last_check'), $this->page($this->owner));
        $this->assertStringNotContainsString('services.license.', $this->page($this->owner));
        Carbon::setTestNow();
    }

    public function test_reissue_is_for_who_manages_the_service_within_what_the_product_allows(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $html = $this->page($viewer);
        $this->assertStringContainsString($this->license->license_key, $html);
        $this->assertStringNotContainsString(__('services.license.reissue_confirm'), $html);

        Setting::query()->where('key', 'allow_reissue')->update(['value' => '0']);
        $this->assertStringNotContainsString(__('services.license.reissue_confirm'), $this->page($this->owner));
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        Livewire::test(Show::class, ['service' => $this->service])->call('reissueLicense');
        $this->assertSame(License::STATUS_ACTIVE, $this->license->fresh()->status, 'refused');
    }

    public function test_staff_see_the_license_reissue_it_past_the_limit_and_edit_its_bindings(): void
    {
        $staff = $this->staff();
        $this->license->update(['reissues' => Licenses::MAX_REISSUES, 'last_ok_at' => now()->subHour(), 'last_seen_ip' => '198.51.100.7', 'last_seen_client' => 'POST curl']);
        LicenseAccessLog::query()->create(['license_id' => $this->license->id, 'message' => 'License Checked', 'ip' => '198.51.100.7', 'created_at' => now()]);

        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()]);
        $page->assertSee($this->license->license_key)->assertSee('POST curl')->assertSee('10 / 10');
        $page->callAction('reissueLicense');
        $this->assertSame([License::STATUS_REISSUED, 11], [$this->license->fresh()->status, $this->license->fresh()->reissues], 'staff: whatever the limit');

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('editLicense', data: ['status' => License::STATUS_ACTIVE, 'valid_ip' => '203.0.113.9', 'valid_domain' => '', 'valid_directory' => '']);
        $this->assertSame([License::STATUS_ACTIVE, '203.0.113.9', null], [$this->license->fresh()->status, $this->license->fresh()->valid_ip, $this->license->fresh()->valid_domain]);

        $this->livewireAs($staff)->test(LicenseAccessLogsRelationManager::class, ['ownerRecord' => $this->service, 'pageClass' => EditService::class])
            ->assertCanSeeTableRecords(LicenseAccessLog::query()->get());
    }

    public function test_the_console_lists_the_silent_and_the_expired_still_calling(): void
    {
        $staff = $this->staff();
        $this->license->update(['last_ok_at' => now()->subDays(30)]); // active, silent
        $other = Service::factory()->create(['client_id' => $this->service->client_id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'cancelled', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1]);
        $expired = License::query()->create(['service_id' => $other->id, 'license_key' => 'ZCAgent-00000000000000ff', 'status' => License::STATUS_EXPIRED,
            'reissues' => 0, 'last_access' => now()->subDay()]);

        $this->livewireAs($staff)->test(ListLicenses::class)->assertCanSeeTableRecords([$this->license, $expired]);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'silent'])->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$this->license])->assertCanNotSeeTableRecords([$expired]);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'calling'])->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$this->license]);

        $this->actingAs($this->staff(['admin.tickets.viewAny']), 'staff')->get(LicenseResource::getUrl())->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\BulkLicense;
use App\Models\ClientUser;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Licensing\BulkLicenseIps;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customer's bulk license screen (docs/zonecloud-bulk-license.md §7.1, mockup approved 2026-11-12): the list, a
 * summary, add with a preview within the limit, rename, replace, remove one or many, a CSV; who may only view sees it.
 */
class BulkLicenseClientTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'TestSecret', 'bulk' => '1', 'tiers' => '1=1.80, 51=1.50', 'max_ips' => '5'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => $key === 'bulk' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }
        $this->owner = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => '2026-12-01']);
        ExtensionHelper::createServer($this->service);
        $this->service->load('license');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function screen(?User $as = null)
    {
        $as ??= $this->owner;
        $this->actingAs($as);
        app(CurrentClient::class)->switchTo($this->service->client_id);

        return Livewire::actingAs($as)->test(BulkLicense::class, ['service' => $this->service]);
    }

    public function test_add_shows_what_it_would_do_before_it_does_it(): void
    {
        $screen = $this->screen()->set('add', "198.51.100.7 web1\n198.51.100.8")->call('previewAdd');

        $this->assertSame(['198.51.100.7', '198.51.100.8'], $screen->get('preview')['added']);
        $this->assertSame('3.60', $screen->get('preview')['cost']);
        $this->assertSame(0, LicenseIp::query()->count(), 'a preview writes nothing');

        $screen->call('confirmAdd')->assertSet('add', '')->assertSet('preview', null);
        $this->assertSame(['198.51.100.7' => 'web1', '198.51.100.8' => null], LicenseIp::query()->orderBy('id')->pluck('label', 'ip')->all());
    }

    public function test_add_adds_only_what_was_previewed(): void
    {
        $screen = $this->screen()->set('add', '198.51.100.7')->call('confirmAdd');
        $this->assertSame(0, LicenseIp::query()->count(), 'no preview: nothing');

        $screen->call('previewAdd')->set('add', "198.51.100.7\n198.51.100.8")->call('confirmAdd');
        $this->assertSame(0, LicenseIp::query()->count(), 'changed after the preview: nothing');

        $screen->call('previewAdd')->call('confirmAdd');
        $this->assertSame(2, LicenseIp::query()->count());
    }

    public function test_a_paste_over_the_limit_is_refused_before_anything_is_looked_up(): void
    {
        $text = implode("\n", array_map(fn ($i) => '10.1.' . intdiv($i, 250) . '.' . ($i % 250 + 1), range(1, 50)));

        $screen = $this->screen()->set('add', $text)->call('previewAdd');

        $this->assertStringContainsString('50', (string) $screen->get('preview')['error']);
    }

    public function test_the_history_is_the_changes_not_the_checks(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        BulkLicenseIps::add($this->service->license, implode("\n", array_map(fn ($i) => '10.2.0.' . $i, range(1, 30))));
        Carbon::setTestNow('2026-11-05 10:00:00');
        BulkLicenseIps::remove($this->service->license, [LicenseIp::query()->where('ip', '10.2.0.1')->value('id')]);
        Carbon::setTestNow('2026-11-12 10:00:00');
        LicenseIp::query()->active()->update(['last_ok_at' => now()]); // every running server checks in: updated_at moves

        $history = $this->screen()->viewData('history');

        $this->assertFalse($history->first()['added'], 'the latest change is the removal');
        $this->assertSame('10.2.0.1', $history->first()['ip']);
    }

    public function test_a_bad_line_is_shown_and_nothing_is_added(): void
    {
        $screen = $this->screen()->set('add', "198.51.100.7\nweb-two")->call('previewAdd');

        $this->assertStringContainsString('web-two', (string) $screen->get('preview')['error']);
        $screen->call('confirmAdd');
        $this->assertSame(0, LicenseIp::query()->count());
    }

    public function test_the_customer_stays_within_the_limit_and_staff_do_not(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1\n198.51.100.2\n198.51.100.3\n198.51.100.4");

        $screen = $this->screen()->set('add', "198.51.100.5\n198.51.100.6")->call('previewAdd');
        $this->assertStringContainsString('5', (string) $screen->get('preview')['error']);
        $screen->call('confirmAdd');
        $this->assertSame(4, LicenseIp::query()->active()->count(), 'the limit of 5');

        $this->screen()->set('add', '198.51.100.5')->call('previewAdd')->call('confirmAdd');
        $this->assertSame(5, LicenseIp::query()->active()->count());
        BulkLicenseIps::add($this->service->license, '198.51.100.6'); // staff
        $this->assertSame(6, LicenseIp::query()->active()->count());

        $this->service->license->update(['max_ips' => 10]);
        $this->screen()->set('add', '198.51.100.7')->call('previewAdd')->call('confirmAdd');
        $this->assertSame(7, LicenseIp::query()->active()->count(), 'its own limit set by staff');
    }

    public function test_rename_replace_and_remove(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1 web1\n198.51.100.2 web2\n198.51.100.3");
        [$one, $two, $three] = LicenseIp::query()->orderBy('id')->pluck('id')->all();

        $this->screen()->call('startEdit', $three)->set('editLabel', 'db1')->call('saveEdit');
        $this->assertSame('db1', LicenseIp::query()->find($three)->label);

        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->screen()->call('startReplace', $one)->set('replaceIp', '203.0.113.9')->call('saveReplace');
        $this->assertNotNull(LicenseIp::query()->find($one)->removed_at);
        $this->assertSame('web1', LicenseIp::query()->active()->where('ip', '203.0.113.9')->sole()->label, 'the new server keeps the name');

        $this->screen()->set('selected', [$two, $three])->call('removeSelected');
        $this->assertSame(['203.0.113.9'], LicenseIp::query()->active()->pluck('ip')->all());
    }

    public function test_replace_by_an_ip_already_on_the_list_changes_nothing(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1 web1\n198.51.100.2");
        $one = LicenseIp::query()->where('ip', '198.51.100.1')->value('id');

        $this->screen()->call('startReplace', $one)->set('replaceIp', '198.51.100.2')->call('saveReplace');

        $this->assertSame(['198.51.100.1', '198.51.100.2'], LicenseIp::query()->active()->orderBy('ip')->pluck('ip')->all());
    }

    public function test_search_and_filters(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1 web1\n198.51.100.2 web2\n198.51.100.3 db1");
        LicenseIp::query()->where('ip', '198.51.100.1')->update(['last_ok_at' => now()->subHour()]);
        LicenseIp::query()->where('ip', '198.51.100.2')->update(['last_ok_at' => now()->subDays(9)]);

        // The rows shown (the recent changes below list every IP anyway).
        $shown = fn ($screen) => collect($screen->viewData('rows')->items())->pluck('ip')->all();
        $screen = $this->screen();
        $this->assertSame(['198.51.100.1', '198.51.100.2', '198.51.100.3'], $shown($screen));
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $shown($screen->set('search', 'web')));
        $this->assertSame(['198.51.100.2'], $shown($screen->set('search', '')->set('filter', 'silent')));
        $this->assertSame(['198.51.100.3'], $shown($screen->set('filter', 'never')));
        $this->assertSame([], $shown($screen->set('filter', 'all')->set('search', '%')), 'a % is searched for, not a wildcard');
    }

    public function test_a_login_that_may_only_view_sees_the_list_and_changes_nothing(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.1');
        $viewer = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);

        $screen = $this->screen($viewer)->assertSee('198.51.100.1')->assertDontSee(__('services.bulk_license.add'));
        $screen->set('add', '198.51.100.9')->call('confirmAdd')->assertStatus(404);
        $this->screen($viewer)->call('remove', LicenseIp::query()->value('id'))->assertStatus(404);
        $this->assertSame(['198.51.100.1'], LicenseIp::query()->active()->pluck('ip')->all());
    }

    public function test_another_customer_cannot_open_it(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other);
        Livewire::actingAs($other)->test(BulkLicense::class, ['service' => $this->service])->assertStatus(404);
    }

    public function test_the_csv_keeps_a_name_from_becoming_a_formula(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1 =HYPERLINK(1)\n198.51.100.2 web2");

        $csv = $this->screen()->call('csv')->effects['download']['content'] ?? null;

        $content = base64_decode((string) $csv);
        $this->assertStringContainsString("198.51.100.1,'=HYPERLINK(1)", $content);
        $this->assertStringContainsString('198.51.100.2,web2', $content);
    }

    public function test_this_period_shows_what_was_billed_and_where_it_is_heading(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1\n198.51.100.2");
        $invoice = $this->service->invoices()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'due_at' => '2026-11-01', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_id' => $this->service->id, 'reference_type' => Service::class, 'price' => 1.80, 'quantity' => 1,
            'description' => 'November', 'period_start' => '2026-11-01', 'period_end' => '2026-12-01']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']); // paid

        $this->screen()->assertSee(__('services.bulk_license.billed', ['amount' => '1.80']))
            ->assertSee(__('services.bulk_license.estimate', ['amount' => '3.60']))
            ->assertSee(__('services.bulk_license.correction', ['amount' => '+1.80']));
    }

    public function test_the_service_page_shows_the_screen(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.1 web1');

        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]))
            ->get(route('services.show', $this->service))->assertOk()->assertSee('198.51.100.1')->assertSee('web1')
            ->assertSeeLivewire(BulkLicense::class);
    }
}

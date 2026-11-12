<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\BulkLicense;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Licensing\BulkUsage;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #825 (the customer's bulk license screen): failing tests for what a customer can do with it.
 */
class BulkLicenseClientAdversarialTest extends TestCase
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

    private function zlic(string $ip): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/zlic.php', ['license' => $this->service->license->license_key, 'license_PSK' => 'TestSecret'])->getContent(), true);
    }

    private function ips(): array
    {
        return LicenseIp::query()->active()->orderBy('ip')->pluck('ip')->all();
    }

    /**
     * ADV-825-1 (medium, billing): the screen lets the customer add an IP and take it off again in the same second (a
     * script of two Livewire calls). In between, zlic answers that IP Active with a localkey and the paid-up due date,
     * so the agent runs to the end of the month (§3, §6.0: «every IP that was on the list during it is billed»). The
     * row has added_at = removed_at, which UsageCharge::distinctUnits skips («there at all»): the month bills 0 IPs.
     * Before this PR only staff could remove; now any customer can, as often as 30 times a minute.
     */
    public function test_an_ip_added_and_removed_in_the_same_second_after_a_good_check_is_still_billed(): void
    {
        $invoice = $this->service->invoices()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'due_at' => '2026-11-01', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_id' => $this->service->id, 'reference_type' => Service::class, 'price' => 0, 'quantity' => 1,
            'description' => 'November', 'period_start' => '2026-11-01', 'period_end' => '2026-12-01']);

        $this->screen()->set('add', '198.51.100.7 web1')->call('confirmAdd');
        $answer = $this->zlic('198.51.100.7');
        $this->assertSame('Active', $answer['status'] ?? null, 'zlic answers the IP while it is on the list');
        $this->assertNotEmpty($answer['localkey'] ?? null);
        $this->screen()->call('remove', LicenseIp::query()->value('id'));
        $this->assertSame([], $this->ips());

        $period = BulkUsage::thisPeriod($this->service->fresh());
        $this->assertSame(1, $period['ips'] ?? null, 'an IP that got a good answer this month is one of its distinct IPs');
    }

    /**
     * ADV-825-2 (medium): rename takes any text, so a name may hold «,» «;» or a new line; replace rebuilds the add text
     * as «new-ip name» and parses it again, so the name is split into entries. A server renamed «web1, 198.51.100.50»
     * and then replaced puts TWO IPs on the list (both billed for the month, 198.51.100.50 never asked for) and the name
     * is cut to «web1». A name like «rack 3; row 2» makes every replace of that row fail («row» is not an IP address).
     */
    public function test_replace_keeps_the_name_as_it_is_and_adds_only_the_new_ip(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.1 web1');
        $id = LicenseIp::query()->value('id');

        $this->screen()->call('startEdit', $id)->set('editLabel', 'web1, 198.51.100.50')->call('saveEdit');
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->screen()->call('startReplace', $id)->set('replaceIp', '203.0.113.9')->call('saveReplace');

        $this->assertSame(['203.0.113.9'], $this->ips(), 'replace adds one IP, the one typed');
        $this->assertSame('web1, 198.51.100.50', LicenseIp::query()->active()->where('ip', '203.0.113.9')->value('label'));
    }

    public function test_replace_works_for_a_name_with_a_semicolon(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.1');
        $id = LicenseIp::query()->value('id');
        $this->screen()->call('startEdit', $id)->set('editLabel', 'rack 3; row 2')->call('saveEdit');

        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->screen()->call('startReplace', $id)->set('replaceIp', '203.0.113.9')->call('saveReplace');

        $this->assertSame(['203.0.113.9'], $this->ips());
    }

    /**
     * ADV-825-3 (low): a list staff took past the customer's limit (staff add without one, or a limit lowered in «Edit
     * license») cannot have any server replaced by the customer: replace removes one and adds one, the count does not
     * grow, yet add() counts it against max_ips and refuses. Remove-then-add (two steps) does the same and is allowed.
     */
    public function test_replace_is_allowed_on_a_list_above_the_limit_since_it_does_not_grow(): void
    {
        BulkLicenseIps::add($this->service->license, "198.51.100.1\n198.51.100.2\n198.51.100.3\n198.51.100.4\n198.51.100.5\n198.51.100.6"); // staff, limit 5
        $one = LicenseIp::query()->where('ip', '198.51.100.1')->value('id');

        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->screen()->call('startReplace', $one)->set('replaceIp', '203.0.113.9')->call('saveReplace');

        $this->assertContains('203.0.113.9', $this->ips(), 'a replace keeps the count: 6 before, 6 after');
        $this->assertNotContains('198.51.100.1', $this->ips());
    }

    /**
     * ADV-825-4 (low): the CSV is UTF-8 without a BOM, so a Greek customer's names («Αθήνα 1») open garbled in Excel.
     * The staff's income CSV writes the BOM for exactly this (IncomeExportController::csv, F7 of #280).
     */
    public function test_the_csv_opens_greek_names_in_excel(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.1 Αθήνα 1');

        $content = base64_decode((string) ($this->screen()->call('csv')->effects['download']['content'] ?? ''));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 with its BOM, as the income CSV');
    }
}

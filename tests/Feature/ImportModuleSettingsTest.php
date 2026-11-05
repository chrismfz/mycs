<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WG-16: a product's module settings beyond the package, as the extension here reads them: a cPanel reseller product's
 * limits (disk and bandwidth only with «Limit Reseller by Usage», as WHMCS), mailcow's limits, Centova's template. A
 * cPanel theme and a plain product's other options are not brought; a reseller product's services are marked WHM
 * resellers. Fake data only.
 */
class ImportModuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'EUR']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, "default" INTEGER)',
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, credit REAL DEFAULT 0)',
            'CREATE TABLE tblproductgroups (id INTEGER, name TEXT, slug TEXT, tagline TEXT, "order" INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproducts (id INTEGER, gid INTEGER, type TEXT, name TEXT, slug TEXT, description TEXT, hidden INTEGER, retired INTEGER, tax INTEGER,
                paytype TEXT, autosetup TEXT, proratabilling INTEGER, proratadate INTEGER, proratachargenextmonth INTEGER, stockcontrol INTEGER, qty INTEGER,
                allowqty INTEGER, servertype TEXT, servergroup INTEGER, configoption1 TEXT, configoption2 TEXT, configoption3 TEXT, configoption4 TEXT, configoption5 TEXT, configoption6 TEXT, configoption7 TEXT, configoption8 TEXT, configoption9 TEXT, configoption10 TEXT, configoption11 TEXT, configoption12 TEXT, configoption13 TEXT, configoption14 TEXT, configoption15 TEXT, configoption16 TEXT, configoption17 TEXT, configoption18 TEXT, configoption19 TEXT, configoption20 TEXT, configoption21 TEXT, configoption22 TEXT, configoption23 TEXT, configoption24 TEXT)',
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee REAL, qsetupfee REAL, ssetupfee REAL, asetupfee REAL,
                bsetupfee REAL, tsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tblproductconfigoptions (id INTEGER, gid INTEGER, optionname TEXT, optiontype INTEGER, "order" INTEGER, hidden INTEGER)',
            'CREATE TABLE tblproductconfigoptionssub (id INTEGER, configid INTEGER, optionname TEXT, sortorder INTEGER)',
            'CREATE TABLE tblproductconfiglinks (id INTEGER, gid INTEGER, pid INTEGER)',
            'CREATE TABLE tblproduct_upgrade_products (product_id INTEGER, upgrade_product_id INTEGER)',
            'CREATE TABLE tblservers (id INTEGER, name TEXT, type TEXT, hostname TEXT, ipaddress TEXT, port INTEGER, username TEXT, maxaccounts INTEGER, active INTEGER, disabled INTEGER)',
            'CREATE TABLE tblservergroups (id INTEGER, name TEXT, filltype INTEGER)',
            'CREATE TABLE tblservergroupsrel (groupid INTEGER, serverid INTEGER)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '€', '', 3, 1)",
            "INSERT INTO tblproductgroups VALUES (3, 'Hosting', 'hosting', '', 1, 0)",
            "INSERT INTO tblproducts VALUES
                (30, 3, 'reselleraccount', 'Reseller 30GB', 'r30', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 0, 'Business10', '', '', '', '', '', '', '', '', '', '', '', '', '', '100', 'on', '15360', '307200', '', 'on', 'resellers', 'on', '', 'on'),
                (31, 3, 'reselleraccount', 'Reseller 70', 'r70', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 0, 'Business10', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '70000', '4000000', '', '', '', '', '', ''),
                (32, 3, 'other', 'MyMail 50', 'mm50', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'mailcow', 0, '100', '100', '1024', '10240', '51200', '100', 'h', 'on', 'dkim', '2048', '1', 'lock_only', 'on', 'on', 'on', '', '', '', '', '', '', '', '', ''),
                (33, 3, 'other', 'Shoutcast v2 100', 'sc100', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'centovacast', 0, 'shoutcast_2_100', '', '', '', '', 'yes', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''),
                (34, 3, 'hostingaccount', 'Personal2', 'p2', '', 0, 0, 1, 'recurring', 'payment', 0, 0, 0, 0, 0, 0, 'cpanel', 0, 'Personal2', '', '', '', '', '', '', '', '', '', '', '', 'paper_lantern', '', '', '', '', '', '', '', '', '', '', '')",
            "INSERT INTO tblservers VALUES (2, 'cp1', 'cpanel', 'cp1.example.test', '192.0.2.10', 0, 'root', 0, 1, 0)",
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function import(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        foreach (['importServers', 'importCatalog'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    /** @return array<string, string> */
    private function settings(int $whmcsId): array
    {
        $product = Product::query()->where('whmcs_id', $whmcsId)->sole();

        return Setting::query()->where('settingable_type', $product->getMorphClass())->where('settingable_id', $product->id)->pluck('value', 'key')->all();
    }

    public function test_module_settings_come_as_the_extensions_read_them(): void
    {
        $this->import();

        $this->assertSame(['package' => 'Business10', 'reseller' => '1', 'reseller_acl' => 'resellers', 'reseller_max_accounts' => '100',
            'reseller_disk_mb' => '15360', 'reseller_bandwidth_mb' => '307200', 'reseller_overselling_disk' => '0', 'reseller_overselling_bandwidth' => '1',
            'reseller_owns_itself' => '1'], $this->sorted($this->settings(30), ['package', 'reseller', 'reseller_acl', 'reseller_max_accounts', 'reseller_disk_mb',
                'reseller_bandwidth_mb', 'reseller_overselling_disk', 'reseller_overselling_bandwidth', 'reseller_owns_itself']));
        $r70 = $this->settings(31);
        $this->assertSame('1', $r70['reseller']);
        $this->assertArrayNotHasKey('reseller_disk_mb', $r70, 'no «Limit Reseller by Usage»: WHMCS applied no disk limit, nor here');
        $this->assertSame('0', $r70['reseller_owns_itself']);

        $mail = $this->settings(32);
        $this->assertSame(['100', '100', '1024', '10240', '51200', '100', 'h', '1', 'dkim', '2048', '1', 'lock_only', '1', '1'],
            [$mail['aliases'], $mail['mailboxes'], $mail['defquota'], $mail['maxquota'], $mail['quota'], $mail['rl_value'], $mail['rl_frame'],
                $mail['create_dkim'], $mail['dkim_selector'], $mail['dkim_keysize'], $mail['restart_sogo'], $mail['terminate_mode'],
                $mail['lock_on_suspend'], $mail['force_lock_multi']]);

        $this->assertSame(['shoutcast_2_100', '1'], [$this->settings(33)['template'], $this->settings(33)['autostart']]);
        $this->assertSame(['package' => 'Personal2'], $this->settings(34), 'a plain product: its package only, never its theme');
    }

    /** Review of #663 (R663-2): a re-run drops what WHMCS no longer sets, never an older run's left behind. */
    public function test_a_rerun_drops_settings_whmcs_no_longer_sets(): void
    {
        $this->import();
        $this->whmcs->exec("UPDATE tblproducts SET type = 'hostingaccount' WHERE id = 30");
        $this->whmcs->exec("UPDATE tblproducts SET configoption16 = '' WHERE id = 31");

        $this->import();

        $this->assertSame(['package' => 'Business10'], $this->settings(30), 'no longer a reseller product: its reseller settings gone');
        $this->assertArrayNotHasKey('reseller_disk_mb', $this->settings(31));
    }

    public function test_a_reseller_products_services_are_marked_whm_resellers(): void
    {
        $this->import();
        $reseller = Product::query()->where('whmcs_id', 30)->sole();
        $plain = Product::query()->where('whmcs_id', 34)->sole();
        $client = User::factory()->create()->client();
        $one = Service::factory()->create(['client_id' => $client->id, 'product_id' => $reseller->id]);
        $two = Service::factory()->create(['client_id' => $client->id, 'product_id' => $plain->id]);
        $command = app(ImportFromWhmcs::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        (new ReflectionMethod($command, 'markCpanelResellers'))->invoke($command);

        $mark = fn ($service) => DB::table('properties')->where('model_type', Service::class)->where('model_id', $service->id)->where('key', 'cpanel_reseller')->value('value');
        $this->assertSame('1', $mark($one));
        $this->assertNull($mark($two));
    }

    /** @param  list<string>  $keys */
    private function sorted(array $settings, array $keys): array
    {
        return array_combine($keys, array_map(fn (string $key) => $settings[$key] ?? null, $keys));
    }
}

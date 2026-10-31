<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Models\Extension;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\WhmcsAnnouncementImport;
use App\Services\Import\WhmcsEmailHistoryImport;
use App\Services\Import\WhmcsWelcomeSnippetImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * The sweep's templates and history (2026-10-31): a product's own welcome email as its snippet, the last year of what WHMCS
 * mailed as the mail log (scrubbed), the published announcements. Invented data.
 */
class ImportTemplatesHistoryTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, welcomeemail INT)',
            'CREATE TABLE tblemailtemplates (id INTEGER PRIMARY KEY, name TEXT, custom INT, message TEXT)',
            'CREATE TABLE tblemails (id INTEGER PRIMARY KEY, userid INT, subject TEXT, "to" TEXT, message TEXT, date TEXT, failed INT, failure_reason TEXT, pending INT)',
            'CREATE TABLE tblannouncements (id INTEGER PRIMARY KEY, date TEXT, title TEXT, announcement TEXT, published INT, parentid INT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function product(int $whmcsId, bool $licensing = true): Product
    {
        $server = null;
        if ($licensing) {
            $server = Extension::query()->firstOrCreate(['extension' => 'NixpalLicensing', 'type' => 'server'], ['name' => 'nixpal Licensing', 'enabled' => true]);
        }
        $product = Product::factory()->create();
        $product->forceFill(['whmcs_id' => $whmcsId, 'server_id' => $server?->id])->save();

        return $product;
    }

    private function template(int $id, string $message, int $custom = 1): void
    {
        $this->whmcs->prepare('INSERT INTO tblemailtemplates VALUES (?, ?, ?, ?)')->execute([$id, "Tpl{$id}", $custom, $message]);
    }

    private function productRow(int $id, int $template): void
    {
        $this->whmcs->prepare('INSERT INTO tblproducts VALUES (?, ?, ?)')->execute([$id, "P{$id}", $template]);
    }

    private const ZONECLOUD = '<p>Dear {$client_name},</p><p>Your order for {$service_product_name} has now been activated. Please keep this message for your records.</p>'
        . '<p>Product/Service: {$service_product_name}<br />License Key: {$service_domain}<br />Payment Method: {$service_payment_method}<br />Amount: {$service_recurring_amount}</p>'
        . '<p> </p><p>Refer to https://zonecloud.example.test/  for installation instructions.</p><p>Thank you for choosing us.</p><p>{$signature}</p>';

    // ---- welcome snippets -------------------------------------------------------------------------------------------------

    public function test_a_licensing_products_template_becomes_its_key_and_its_instructions(): void
    {
        $product = $this->product(4);
        $this->template(84, self::ZONECLOUD);
        $this->productRow(4, 84);

        $result = (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $snippet = $product->fresh()->email_template;
        $this->assertStringContainsString('{{ service.license.license_key }}', $snippet);
        $this->assertStringContainsString('<https://zonecloud.example.test/>', $snippet);
        $this->assertStringNotContainsString('Dear', $snippet);
        $this->assertStringNotContainsString('Payment Method', $snippet);
        $this->assertStringNotContainsString('{$', $snippet);
        $this->assertSame(1, $result['counts']['snippets']);
        $this->assertTrue(TemplateRenderer::rule() !== null, 'the product form validates it with this rule');
    }

    public function test_a_product_of_the_full_import_is_found_by_its_id(): void
    {
        $product = $this->product(1234);
        $product->forceFill(['id' => 4321, 'whmcs_id' => null])->save(); // the full import: WHMCS's id, no whmcs_id
        DB::table('products')->where('id', $product->id)->update(['id' => 77]);
        $this->template(84, self::ZONECLOUD);
        $this->productRow(77, 84);

        (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $this->assertStringContainsString('{{ service.license.license_key }}', (string) DB::table('products')->where('id', 77)->value('email_template'));
    }

    public function test_a_stock_template_a_snippet_staff_wrote_and_a_line_with_an_unknown_variable_are_left(): void
    {
        $stock = $this->product(5);
        $mine = $this->product(6);
        $mine->forceFill(['email_template' => 'my own'])->save();
        $odd = $this->product(7, licensing: false);
        $this->template(1, 'Welcome {$client_name}', custom: 0);
        $this->template(85, self::ZONECLOUD);
        $this->template(86, '<p>Your panel: {$service_dedicated_ip_x}</p><p>Read https://docs.example.test/</p>');
        $this->productRow(5, 1);
        $this->productRow(6, 85);
        $this->productRow(7, 86);

        $result = (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $this->assertNull($stock->fresh()->email_template);
        $this->assertSame('my own', $mine->fresh()->email_template);
        $this->assertSame('Read <https://docs.example.test/>', $odd->fresh()->email_template);
        $this->assertStringContainsString('service_dedicated_ip_x', implode("\n", $result['notes']));
    }

    public function test_a_key_line_of_a_product_that_is_not_licensing_is_not_guessed(): void
    {
        $plain = $this->product(8, licensing: false);
        $this->template(87, '<p>License Key: {$service_domain}</p><p>Read https://docs.example.test/</p>');
        $this->productRow(8, 87);

        (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $this->assertStringNotContainsString('license_key', (string) $plain->fresh()->email_template);
    }

    // ---- email history ----------------------------------------------------------------------------------------------------

    private function mail(int $id, string $date, string $message = '<p>Hello</p>', int $userid = 0, int $failed = 0, int $pending = 0): void
    {
        $this->whmcs->prepare('INSERT INTO tblemails VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $userid, "Subject {$id}", 'A Person <a@example.test>', $message, $date, $failed, $failed ? 'refused' : '', $pending]);
    }

    public function test_the_last_year_comes_as_the_mail_log_and_the_retention_covers_it(): void
    {
        $client = User::factory()->create()->client();
        $this->mail(1, '2026-10-01 10:00:00', userid: $client->id);
        $this->mail(2, '2024-01-01 10:00:00');
        $this->mail(3, '2026-09-01 10:00:00', failed: 1);
        $this->mail(4, '2026-09-02 10:00:00', pending: 1);

        $result = (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $this->assertSame(['mails' => 2, 'older' => 1, 'pending' => 1], array_intersect_key($result['counts'], array_flip(['mails', 'older', 'pending'])));
        $this->assertSame(['sent', 'failed'], DB::table('email_logs')->orderBy('id')->pluck('status')->all());
        $this->assertSame($client->id, (int) DB::table('email_logs')->orderBy('id')->value('client_id'));
        $this->assertNull(DB::table('email_logs')->orderByDesc('id')->value('client_id'));
        $this->assertSame('365', Setting::query()->where('key', 'cronjob_delete_email_logs')->whereNull('settingable_type')->value('value'));
    }

    public function test_passwords_and_link_keys_are_masked_in_the_bodies(): void
    {
        $this->mail(1, '2026-10-01 10:00:00', '<p>Username: bob<br />Password: Sup3r-Secret!</p><p>Reset: https://clients.example.test/pwreset.php?key=abcDEF123456&amp;id=7</p><p>Your passwd = hunter2</p>');

        $result = (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $body = (string) DB::table('email_logs')->value('body');
        foreach (['Sup3r-Secret', 'abcDEF123456', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('Username: bob', $body);
        $this->assertSame(1, $result['counts']['scrubbed']);
    }

    // ---- announcements ----------------------------------------------------------------------------------------------------

    public function test_published_announcements_come_with_their_dates_once_each(): void
    {
        if (!Schema::hasTable('ext_announcements')) {
            Schema::create('ext_announcements', function ($table) {
                $table->id();
                $table->string('title');
                $table->string('description');
                $table->text('content');
                $table->boolean('is_active')->default(true);
                $table->dateTime('published_at')->nullable();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }
        foreach ([[1, '2024-11-07 10:49:00', 'cPanel views', '<p>News</p>', 1, 0], [2, '2024-11-08 10:00:00', 'cPanel views', '<p>Same title</p>', 1, 0],
            [3, '2024-11-09 10:00:00', 'Draft', 'x', 0, 0], [4, '2024-11-10 10:00:00', 'Translated', 'x', 1, 1]] as $row) {
            $this->whmcs->prepare('INSERT INTO tblannouncements VALUES (?, ?, ?, ?, ?, ?)')->execute($row);
        }

        $result = (new WhmcsAnnouncementImport($this->whmcs))->run();

        $this->assertSame(2, $result['counts']['announcements']);
        $this->assertSame(2, DB::table('ext_announcements')->distinct()->count('slug'));
        $this->assertSame('2024-11-07 10:49:00', DB::table('ext_announcements')->orderBy('id')->value('published_at'));
        $this->assertSame(2, $result['counts']['skipped']);
    }

    public function test_without_the_extension_the_announcements_are_said_not_imported(): void
    {
        Schema::dropIfExists('ext_announcements');
        $this->whmcs->prepare('INSERT INTO tblannouncements VALUES (?, ?, ?, ?, ?, ?)')->execute([1, '2024-11-07 10:49:00', 'News', 'x', 1, 0]);

        $result = (new WhmcsAnnouncementImport($this->whmcs))->run();

        $this->assertSame(0, $result['counts']['announcements']);
        $this->assertStringContainsString('extension is not installed', implode("\n", $result['notes']));
    }
}

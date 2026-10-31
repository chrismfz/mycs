<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Livewire\Client\Emails;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\WhmcsEmailHistoryImport;
use App\Services\Import\WhmcsWelcomeSnippetImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #564 (importer: welcome snippets, mail history, announcements). Invented data only.
 */
class ImportTemplatesHistoryAdversarialTest extends TestCase
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
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function mail(string $message, int $userid = 0, int $id = 1): void
    {
        $this->whmcs->prepare('INSERT INTO tblemails VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0)')
            ->execute([$id, $userid, "Subject {$id}", 'a@example.test', $message, '2026-10-01 10:00:00', '']);
    }

    private function importedBody(string $message): string
    {
        $this->mail($message);
        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        return (string) DB::table('email_logs')->value('body');
    }

    private function productWithTemplate(string $message): Product
    {
        $this->whmcs->prepare('INSERT INTO tblemailtemplates VALUES (1, ?, 1, ?)')->execute(['T', $message]);
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (1, 'P', 1)");
        $product = Product::factory()->create();
        $product->forceFill(['whmcs_id' => 1])->save();

        return $product;
    }

    /** ADV-564-1 (High): WHMCS's Greek template says «Κωδικός πρόσβασης:», two words before the colon. */
    public function test_a_greek_password_line_is_masked(): void
    {
        $body = $this->importedBody('<p>Όνομα χρήστη: bob<br />Κωδικός πρόσβασης: Gr33kSecret9</p>');

        $this->assertStringNotContainsString('Gr33kSecret9', $body);
    }

    /** ADV-564-1: the editor's «&nbsp;» between the label and the value. */
    public function test_a_password_after_a_non_breaking_space_is_masked(): void
    {
        $body = $this->importedBody('<p>Password:&nbsp;NbspSecret1</p>');

        $this->assertStringNotContainsString('NbspSecret1', $body);
    }

    /** ADV-564-1: «password is: …». */
    public function test_a_password_given_after_is_is_masked(): void
    {
        $body = $this->importedBody('<p>Your new password is: IsSecret9</p>');

        $this->assertStringNotContainsString('IsSecret9', $body);
    }

    /** ADV-564-1: a password that begins with «&» is skipped by the value pattern. */
    public function test_a_password_that_starts_with_an_ampersand_is_masked(): void
    {
        $body = $this->importedBody('<p>Password: &Amp5ecret</p>');

        $this->assertStringNotContainsString('Amp5ecret', $body);
    }

    /** ADV-564-2 (High): a link key in an HTML body follows «&amp;», so its name reads «amp;key». */
    public function test_a_key_after_an_escaped_ampersand_is_masked(): void
    {
        $body = $this->importedBody('<p><a href="https://clients.example.test/pwreset.php?id=7&amp;key=AmpKey123456">reset</a></p>');

        $this->assertStringNotContainsString('AmpKey123456', $body);
    }

    /** ADV-564-2: WHMCS 7+ puts the reset token in the path of «rp=». */
    public function test_a_reset_token_in_the_rp_path_is_masked(): void
    {
        $body = $this->importedBody('<p><a href="https://clients.example.test/index.php?rp=/password/reset/redeem/RpToken0123456789abcdef">reset</a></p>');

        $this->assertStringNotContainsString('RpToken0123456789abcdef', $body);
    }

    /** ADV-564-3 (Medium): the class docblock says «so a customer's page still shows what they were sent». */
    public function test_an_imported_mail_is_on_the_customers_my_emails_page(): void
    {
        $client = User::factory()->create()->client();
        $this->mail('<p>Hello</p>', userid: $client->id);
        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $this->assertSame(1, DB::table('email_logs')->where('client_id', $client->id)->count());
        $this->assertSame(1, Emails::forAccount($client->id)->count(), 'imported rows have no template_key, so «My emails» never lists them');
    }

    /** ADV-564-4 (Medium): 365 replaces a longer retention. */
    public function test_a_longer_retention_is_not_lowered(): void
    {
        Setting::query()->updateOrCreate(['key' => 'cronjob_delete_email_logs', 'settingable_type' => null, 'settingable_id' => null], ['value' => '730', 'type' => 'string']);
        $this->mail('<p>Hello</p>');

        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $this->assertSame('730', Setting::query()->where('key', 'cronjob_delete_email_logs')->value('value'));
    }

    /** ADV-564-4: and «0» (for ever) becomes a limit. */
    public function test_a_retention_of_for_ever_is_not_lowered(): void
    {
        Setting::query()->updateOrCreate(['key' => 'cronjob_delete_email_logs', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'string']);
        $this->mail('<p>Hello</p>');

        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $this->assertSame('0', Setting::query()->where('key', 'cronjob_delete_email_logs')->value('value'));
    }

    /** ADV-564-5 (Medium): the class run twice (the command empties the database first; the class can be called alone). */
    public function test_running_the_history_twice_does_not_double_the_log(): void
    {
        $this->mail('<p>Hello</p>');

        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();
        (new WhmcsEmailHistoryImport($this->whmcs, '2025-10-31 00:00:00'))->run();

        $this->assertSame(1, DB::table('email_logs')->count());
    }

    /** ADV-564-6 (Medium): tags written as entities are decoded AFTER strip_tags and reach the email as live HTML. */
    public function test_a_tag_written_as_an_entity_does_not_become_html_in_the_snippet(): void
    {
        $product = $this->productWithTemplate('<p>&lt;img src="https://tracker.example.test/p.gif" onerror="alert(1)"&gt;</p><p>Read https://docs.example.test/</p>');

        (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $html = TemplateRenderer::renderMarkdown((string) $product->fresh()->email_template, []);
        $this->assertStringNotContainsString('<img', $html);
    }

    /** ADV-564-7 (Medium): Smarty other than «{$name}» is not seen, so it is carried as text the customer reads. */
    public function test_smarty_blocks_and_dotted_variables_are_not_shipped_as_text(): void
    {
        $product = $this->productWithTemplate('<p>Hello {$client_details.firstname}</p><p>{if $service_username}Panel: https://panel.example.test{/if}</p>');

        (new WhmcsWelcomeSnippetImport($this->whmcs))->run();

        $html = TemplateRenderer::renderMarkdown((string) $product->fresh()->email_template, []);
        $this->assertStringNotContainsString('client_details', $html);
        $this->assertStringNotContainsString('{if', $html);
    }

    /** ADV-564-8 (Medium): a body that is not valid UTF-8 (a legacy latin1 row) is blanked by the /u scrub. */
    public function test_a_body_with_a_bad_byte_is_kept(): void
    {
        $body = $this->importedBody("<p>Invoice due \xE9 soon</p>");

        $this->assertNotSame('', $body);
    }
}

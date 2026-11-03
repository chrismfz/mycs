<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use App\Services\Mail\TemplatePreview;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Independent review of #592 (Service::mailName). Tests named *_fails_* prove a finding; the rest check what holds. */
class Review592Test extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2027_01_31_000000_service_mails_say_which_service.php';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.mail_disable' => false]);
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'suspended', 'dunning_suspended_at' => now(), 'price' => 10, 'quantity' => 1,
            'currency_code' => 'USD'] + $attributes);
    }

    private function suspendedMail(Service $service): EmailLog
    {
        NotificationHelper::serverSuspendedNotification($service->client, $service);

        return EmailLog::query()->latest('id')->firstOrFail();
    }

    /** F1: an existing install keeps the old in-app text: the migration reads subject, body and translations only. */
    public function test_f1_fails_the_migration_misses_the_in_app_body_column(): void
    {
        DB::table('notification_templates')->where('key', 'server_suspended')
            ->update(['in_app_body' => 'Your service {{ service.product.name }} was suspended.']);

        (require database_path(self::MIGRATION))->up();

        $this->assertSame('Your service {{ service.mailName }} was suspended.',
            DB::table('notification_templates')->where('key', 'server_suspended')->value('in_app_body'));
    }

    /** F2: a template staff already made name the domain now names it twice (the PR says staff's wording is kept). */
    public function test_f2_fails_a_staff_template_that_already_names_the_domain_names_it_twice(): void
    {
        DB::table('notification_templates')->where('key', 'server_suspended')
            ->update(['subject' => 'Suspended: {{ service.product.name }} ({{ service.domain }})']);
        (require database_path(self::MIGRATION))->up();

        $subject = $this->suspendedMail($this->service(['domain' => 'shop.example.gr']))->subject;

        $this->assertSame(1, substr_count($subject, 'shop.example.gr'), $subject);
    }

    /** F3: a customer's label (255 chars max, also WHMCS's imported descriptions) makes a subject longer than email_logs.subject. */
    public function test_f3_fails_a_long_label_overflows_the_logs_subject_column(): void
    {
        $service = $this->service(['domain' => null, 'label' => str_repeat('Γ', 240)]);

        $subject = $this->suspendedMail($service)->subject;

        // varchar(255) on MariaDB, strict: «Data too long for column 'subject'», the mail is never queued.
        $this->assertLessThanOrEqual(255, mb_strlen($subject));
    }

    /** F4: the admin preview's sample service has a license (no IP) set on it: every service mail previews a license's name. */
    public function test_f4_fails_the_preview_names_a_hosting_service_by_a_masked_license_key(): void
    {
        $preview = TemplatePreview::render(NotificationTemplate::where('key', 'server_suspended')->sole(), 'en');

        $this->assertStringContainsString('example.gr', $preview['subject']);
    }

    /** F5: a license service whose license row is missing (the WHMCS import skips a clash; tblhosting.domain is the key). */
    public function test_f5_fails_a_license_service_without_its_row_mails_its_whole_key(): void
    {
        $mail = $this->suspendedMail($this->service(['domain' => 'NIXPAL-ABCDEFGH1234']));

        $this->assertStringNotContainsString('ABCDEFGH', $mail->subject . $mail->body);
    }

    /** Holds: what a customer typed in the label is text in the body (Markdown and HTML), never a link or a tag. */
    public function test_a_label_with_markup_is_escaped_in_the_body_and_plain_in_the_subject(): void
    {
        $label = '<b>x</b> [click](https://evil.test) **y** {{ app.name }}';
        $mail = $this->suspendedMail($this->service(['domain' => null, 'label' => $label]));

        $this->assertStringContainsString($label, $mail->subject);
        $this->assertStringNotContainsString('<b>x</b>', (string) $mail->body);
        $this->assertStringNotContainsString('href="https://evil.test"', (string) $mail->body);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', (string) $mail->body);
    }

    /** Holds: the Greek translation too, and the migration twice changes nothing more (idempotent). */
    public function test_greek_and_the_migration_is_idempotent(): void
    {
        $row = DB::table('notification_templates')->where('key', 'server_suspended')->first();
        $old = str_replace('{{ service.mailName }}', '{{ service.product.name }}', $row->translations);
        DB::table('notification_templates')->where('key', 'server_suspended')->update(['translations' => $old]);
        (require database_path(self::MIGRATION))->up();
        $once = DB::table('notification_templates')->where('key', 'server_suspended')->value('translations');
        (require database_path(self::MIGRATION))->up();

        $this->assertSame($once, DB::table('notification_templates')->where('key', 'server_suspended')->value('translations'));
        $this->assertStringNotContainsString('service.product.name', $once);

        $template = NotificationTemplate::where('key', 'server_suspended')->sole()->inLocale('el');
        $this->assertStringContainsString('service.mailName', $template->subject);
    }

    /** Holds: invalid JSON and non-string values in translations are left as they are. */
    public function test_the_migration_leaves_invalid_or_odd_translations(): void
    {
        // Invalid JSON only where the column takes it (SQLite): MariaDB's JSON column refuses it, so it never meets the migration.
        $invalid = DB::connection()->getDriverName() === 'sqlite';
        if ($invalid) {
            DB::table('notification_templates')->where('key', 'server_suspended')->update(['translations' => '{not json {{ service.product.name }}']);
        }
        DB::table('notification_templates')->where('key', 'server_unsuspended')
            ->update(['translations' => json_encode(['el' => ['subject' => 'A {{ service.product.name }}', 'n' => 3, 'b' => true, 'x' => null, 'deep' => ['s' => '{{ service.product.name }}']]])]);

        (require database_path(self::MIGRATION))->up();

        if ($invalid) {
            $this->assertSame('{not json {{ service.product.name }}', DB::table('notification_templates')->where('key', 'server_suspended')->value('translations'));
        }
        $this->assertSame(['el' => ['subject' => 'A {{ service.mailName }}', 'n' => 3, 'b' => true, 'x' => null, 'deep' => ['s' => '{{ service.mailName }}']]],
            json_decode(DB::table('notification_templates')->where('key', 'server_unsuspended')->value('translations'), true));
    }

    /** Holds: one query for the license per mail, however many times the name is read (subject, body, in-app, contacts). */
    public function test_the_license_is_read_once_per_mail(): void
    {
        $service = $this->service(['domain' => 'a.example.gr'])->fresh();
        $service->load('product', 'client');
        DB::enableQueryLog();
        NotificationHelper::serverSuspendedNotification($service->client, $service);
        $licenseQueries = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/from [`"]licenses[`"]/', $q['query']) === 1)->count();

        $this->assertSame(1, $licenseQueries);
    }
}

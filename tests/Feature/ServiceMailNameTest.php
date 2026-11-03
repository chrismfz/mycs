<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\License;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The service mails say which service (owner 2026-11-03: «Your service cPanel test 10 was suspended» could be any of
 * them): its product and its domain, its own label, or its license masked (Service::mailName).
 */
class ServiceMailNameTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_suspension_mail_names_the_service_by_its_domain(): void
    {
        $service = $this->service(['domain' => 'mycs-test.gr']);
        $name = $service->product->name;

        $mail = $this->suspendedMail($service);

        $this->assertSame("Service suspended: {$name} — mycs-test.gr", $mail->subject);
        $this->assertStringContainsString("{$name} — mycs-test.gr", (string) $mail->body);
    }

    /** A license: its nickname and the IP it runs on (its domain is its key, never shown whole). */
    public function test_a_license_is_named_by_its_label_and_ip(): void
    {
        $service = $this->service(['domain' => 'NIXPAL-ABCDEFGH1234', 'label' => 'Office']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'NIXPAL-ABCDEFGH1234', 'status' => 'active', 'valid_ip' => '84.54.49.5,213.202.218.46,77.9.1.1']);

        $mail = $this->suspendedMail($service->fresh());

        $this->assertStringEndsWith('— Office · 84.54.49.5 +2', $mail->subject);
        $this->assertStringNotContainsString('ABCDEFGH', $mail->subject . $mail->body, 'never the whole key in a mail');
    }

    /** A license with no label and no IP: its key, masked. */
    public function test_a_bare_license_is_named_by_its_key_masked(): void
    {
        $service = $this->service(['domain' => 'NIXPAL-ABCDEFGH1234']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'NIXPAL-ABCDEFGH1234', 'status' => 'active']);

        $mail = $this->suspendedMail($service->fresh());

        $this->assertStringEndsWith('— NIXPAL-…1234', $mail->subject);
        $this->assertStringNotContainsString('ABCDEFGH', $mail->subject . $mail->body);
    }

    public function test_without_a_domain_its_own_label_names_it(): void
    {
        $service = $this->service(['domain' => null, 'label' => 'Office site']);

        $this->assertStringContainsString('— Office site', $this->suspendedMail($service)->subject);
    }

    public function test_the_migration_changes_only_text_still_as_seeded(): void
    {
        DB::table('notification_templates')->where('key', 'server_suspended')->update(['subject' => 'Service suspended: {{ service.product.name }}']);
        DB::table('notification_templates')->where('key', 'server_unsuspended')->update(['subject' => 'Back: {{ service.product.name }} (staff wrote this)']);
        DB::table('notification_templates')->where('key', 'new_server_created')->update(['subject' => 'Ready: {{ service.username }}']);

        (require database_path('migrations/2027_01_31_000000_service_mails_say_which_service.php'))->up();

        $this->assertSame('Service suspended: {{ service.mailName }}', DB::table('notification_templates')->where('key', 'server_suspended')->value('subject'));
        $this->assertSame('Back: {{ service.mailName }} (staff wrote this)', DB::table('notification_templates')->where('key', 'server_unsuspended')->value('subject'),
            'the name itself, wherever it stands');
        $this->assertSame('Ready: {{ service.username }}', DB::table('notification_templates')->where('key', 'new_server_created')->value('subject'), 'no name: untouched');
    }
}

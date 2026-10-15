<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\SuspendJob;
use App\Mail\Mail;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * «Service suspended» says «renewal overdue» only when the cron suspended the service for it; staff's suspend says
 * «was suspended», with the reason staff wrote.
 */
class SuspensionMailTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUS_EN_BODY = "# Service suspended\n\nDear {{ user.first_name }},\n\nYour service **{{ service.product.name }}** was suspended because its renewal is overdue.\n\n"
        . "Once its invoice is paid, the service is reactivated automatically, usually within minutes.\n\n<div class=\"action\">\n\t<a class=\"button button-blue\" href=\"{{ urls.invoices }}\">\n"
        . "\t\tView my invoices\n\t</a>\n</div>\n\nIf you think this is a mistake, please contact us.";

    private const PREVIOUS_EN_IN_APP = 'Your service {{ service.product.name }} was suspended because its renewal is overdue. Pay its invoice to reactivate it.';

    private const PREVIOUS_EL_BODY = "# Αναστολή υπηρεσίας\n\nΑγαπητέ/ή {{ user.first_name }},\n\nΗ υπηρεσία σας **{{ service.product.name }}** τέθηκε σε αναστολή επειδή η ανανέωσή της είναι ληξιπρόθεσμη.\n\n"
        . "Μόλις εξοφληθεί το προτιμολόγιό της, η υπηρεσία ενεργοποιείται ξανά αυτόματα, συνήθως μέσα σε λίγα λεπτά.\n\n<div class=\"action\">\n\t<a class=\"button button-blue\" href=\"{{ urls.invoices }}\">\n"
        . "\t\tΤα προτιμολόγιά μου\n\t</a>\n</div>\n\nΑν πιστεύετε ότι πρόκειται για λάθος, επικοινωνήστε μαζί μας.";

    private const PREVIOUS_EL_IN_APP = 'Η υπηρεσία σας {{ service.product.name }} τέθηκε σε αναστολή επειδή η ανανέωσή της είναι ληξιπρόθεσμη. Εξοφλήστε το προτιμολόγιό της για να ενεργοποιηθεί ξανά.';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    private function suspensionMail(): Mail
    {
        return MailFacade::queued(Mail::class)->filter(fn (Mail $mail) => $mail->templateKey === 'server_suspended')->sole();
    }

    private function staffSuspends(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => true]);
    }

    public function test_staffs_suspend_does_not_say_overdue_and_gives_staffs_reason(): void
    {
        $this->service->update(['suspend_reason' => 'Abuse report #42']);

        $this->staffSuspends();

        $html = $this->suspensionMail()->renderedHtml;
        $this->assertStringContainsString('was suspended.', $html);
        $this->assertStringContainsString('Abuse report #42', $html);
        $this->assertStringNotContainsString('overdue', $html);
        $this->assertStringNotContainsString('View my invoices', $html);
    }

    public function test_the_crons_suspend_says_overdue(): void
    {
        $this->service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();

        (new SuspendJob($this->service->fresh()))->handle();

        $html = $this->suspensionMail()->renderedHtml;
        $this->assertStringContainsString('because its renewal is overdue', $html);
        $this->assertStringContainsString('View my invoices', $html);
        $this->assertStringNotContainsString('Overdue on Payment', $html, 'the reason is said once, in words');
    }

    public function test_staff_holding_a_service_the_cron_suspended_is_told_as_staffs_in_greek_too(): void
    {
        $this->service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        config(['settings.app_language' => 'el']);

        $this->staffSuspends();

        $html = $this->suspensionMail()->renderedHtml;
        $this->assertStringContainsString('τέθηκε σε αναστολή.', $html);
        $this->assertStringNotContainsString('ληξιπρόθεσμη', $html);
        $this->assertStringNotContainsString('Overdue on Payment', $html, 'the hold clears the cron\'s reason');
        $this->assertNull($this->service->fresh()->dunning_suspended_at);
    }

    public function test_the_live_template_takes_the_new_text_unless_staff_rewrote_it(): void
    {
        $previous = fn (array $el) => ['subject' => 'Service suspended: {{ service.product.name }}', 'body' => self::PREVIOUS_EN_BODY,
            'in_app_title' => 'Service suspended', 'in_app_body' => self::PREVIOUS_EN_IN_APP,
            'translations' => json_encode(['el' => $el], JSON_UNESCAPED_UNICODE)];
        $previousEl = ['subject' => 'Αναστολή υπηρεσίας: {{ service.product.name }}', 'body' => self::PREVIOUS_EL_BODY,
            'in_app_title' => 'Αναστολή υπηρεσίας', 'in_app_body' => self::PREVIOUS_EL_IN_APP];
        $migration = require database_path('migrations/2026_11_16_000000_suspension_mail_says_why.php');

        DB::table('notification_templates')->where('key', 'server_suspended')->update($previous($previousEl));
        $migration->up();
        $row = DB::table('notification_templates')->where('key', 'server_suspended')->sole();
        $this->assertSame(EmailTemplateSeeder::mapping['server_suspended']['body'], $row->body);
        $this->assertSame(EmailTemplateSeeder::translations['server_suspended']['el']['body'], json_decode($row->translations, true)['el']['body']);

        DB::table('notification_templates')->where('key', 'server_suspended')->update(['body' => 'Our own text'] + $previous(['body' => 'Δικό μας'] + $previousEl));
        $migration->up();
        $row = DB::table('notification_templates')->where('key', 'server_suspended')->sole();
        $this->assertSame(['Our own text', 'Δικό μας'], [$row->body, json_decode($row->translations, true)['el']['body']]);

        Log::spy();
        DB::table('notification_templates')->where('key', 'server_suspended')->delete();
        (new EmailTemplateSeeder)->run(); // a fresh install: already the new text, nothing said
        $migration->up();
        Log::shouldNotHaveReceived('notice');
    }
}

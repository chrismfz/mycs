<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\TerminateJob;
use App\Mail\Mail;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * Sweep modules §7 and §21: staff's «Suspend server» mails the customer when «Send Notification» is ticked (as WHMCS);
 * a service ended at the end of its period because its customer asked is told «cancelled as you asked», an immediate
 * request (already told it ends now) gets no second mail, and any other end is «terminated».
 */
class ServiceEndMailsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Stored, not only in config: a setting saved in the run flushes the settings cache back to what is stored (review 1).
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    /** @return list<string> the mails about the service's end (not the sign-up's or the request's confirmation) */
    private function mailed(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)
            ->filter(fn ($key) => in_array($key, ['server_suspended', 'server_terminated', 'service_cancelled'], true))->values()->all();
    }

    private function staffSuspends(bool $notify): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => $notify]);
    }

    public function test_staff_suspend_mails_only_when_asked(): void
    {
        $this->staffSuspends(false);
        $this->assertSame('suspended', $this->service->fresh()->status);
        $this->assertSame([], $this->mailed());

        $this->service->update(['status' => 'active']);
        $this->staffSuspends(true);
        $this->assertSame(['server_suspended'], $this->mailed());
    }

    public function test_the_end_of_a_requested_period_is_told_as_cancelled_not_terminated(): void
    {
        ServiceCancellation::query()->create(['service_id' => $this->service->id, 'type' => 'end_of_period', 'reason' => 'Moving']);
        $this->service->update(['status' => 'cancelled']);

        (new TerminateJob($this->service->fresh()))->handle();

        $this->assertSame(['service_cancelled'], $this->mailed());
    }

    public function test_an_immediate_request_gets_no_second_mail_and_any_other_end_is_terminated(): void
    {
        ServiceCancellation::query()->create(['service_id' => $this->service->id, 'type' => 'immediate', 'reason' => 'Moving']);
        $this->service->update(['status' => 'cancelled']);
        (new TerminateJob($this->service->fresh()))->handle();
        $this->assertSame([], $this->mailed());

        DB::table('service_cancellations')->delete();
        (new TerminateJob($this->service->fresh()))->handle();
        $this->assertSame(['server_terminated'], $this->mailed());
    }

    public function test_a_revived_service_terminated_for_another_reason_is_told_terminated(): void
    {
        ServiceCancellation::query()->create(['service_id' => $this->service->id, 'type' => 'immediate', 'reason' => 'Moving']);
        $this->service->update(['status' => 'terminated']); // staff revived it, the cron ended it for non-payment

        (new TerminateJob($this->service->fresh()))->handle();

        $this->assertSame(['server_terminated'], $this->mailed());
    }

    public function test_staff_suspend_of_a_service_without_a_module_without_notifying_sends_nothing(): void
    {
        $this->staffSuspends(false);

        $this->assertSame(['suspended', []], [$this->service->fresh()->status, $this->mailed()]);
    }

    public function test_the_template_reaches_a_live_database_in_both_languages(): void
    {
        DB::table('notification_templates')->where('key', 'service_cancelled')->delete();

        (require database_path('migrations/2026_11_09_000001_service_cancelled_notification.php'))->up();

        $row = DB::table('notification_templates')->where('key', 'service_cancelled')->sole();
        $this->assertTrue((bool) $row->enabled);
        $this->assertStringContainsString('Διακοπή υπηρεσίας', (string) $row->translations);
    }
}

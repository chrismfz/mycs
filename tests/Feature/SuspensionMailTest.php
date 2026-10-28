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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * «Service suspended» says «renewal overdue» only when the cron suspended the service for it; staff's suspend says
 * «was suspended», with the reason staff wrote.
 */
class SuspensionMailTest extends TestCase
{
    use RefreshDatabase;

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
        $this->storeSettings(['app_language' => 'el']);

        $this->staffSuspends();

        $html = $this->suspensionMail()->renderedHtml;
        $this->assertStringContainsString('τέθηκε σε αναστολή.', $html);
        $this->assertStringNotContainsString('ληξιπρόθεσμη', $html);
        $this->assertStringNotContainsString('Overdue on Payment', $html, 'the hold clears the cron\'s reason');
        $this->assertNull($this->service->fresh()->dunning_suspended_at);
    }
}

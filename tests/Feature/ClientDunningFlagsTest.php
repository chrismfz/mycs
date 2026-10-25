<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientGroupResource\Pages\EditClientGroup;
use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Mail\Mail;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WHMCS's «Exempt from auto suspend/terminate», «Disable overdue notices» and its groups' «Separate invoices» (sweep of
 * myip, 2026-10-25): each on the account, or on its group for all its members.
 */
class ClientDunningFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_suspend_enabled' => true,
            'settings.cronjob_invoice' => 0, 'settings.system_email_address' => 'staff@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(array $own = [], array $group = []): Client
    {
        $client = User::factory()->create()->client();
        if ($group !== []) {
            $client->forceFill(['client_group_id' => ClientGroup::query()->create(['name' => 'G' . uniqid(), 'discount_percent' => 0] + $group)->id]);
        }
        $client->forceFill($own)->save();

        return $client->fresh();
    }

    private function service(Client $client, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(array_merge(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-10'], $attributes));
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $service->expires_at]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-01']);
        DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => 'mailed'])->delete();

        return $service;
    }

    public function test_the_cron_never_suspends_or_terminates_an_exempt_account_or_group(): void
    {
        $plain = $this->service($this->client());
        $own = $this->service($this->client(['dunning_exempt' => true]));
        $grouped = $this->service($this->client([], ['dunning_exempt' => true]));
        $suspended = $this->service($this->client(['dunning_exempt' => true]), ['status' => Service::STATUS_SUSPENDED, 'expires_at' => '2026-09-01',
            'dunning_suspended_at' => '2026-09-04']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $plain->fresh()->status);
        $this->assertSame([Service::STATUS_ACTIVE, Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED],
            [$own->fresh()->status, $grouped->fresh()->status, $suspended->fresh()->status], 'neither suspended nor terminated');
    }

    public function test_no_overdue_reminder_for_the_account_or_its_group_but_the_one_before_the_due_date_still_goes(): void
    {
        $this->service($this->client(['no_overdue_reminders' => true]), ['expires_at' => '2026-10-19']);
        $this->service($this->client([], ['no_overdue_reminders' => true]), ['expires_at' => '2026-10-19']);
        $soon = $this->service($this->client(['no_overdue_reminders' => true]), ['expires_at' => '2026-10-24']);
        $this->service($this->client(), ['expires_at' => '2026-10-19']);
        MailFacade::fake();

        (new PaymentReminders)->send();

        $sent = MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->sort()->values()->all();
        $this->assertSame(['invoice_overdue', 'invoice_payment_reminder'], $sent, 'one overdue (the plain account), one before its due date');
        $this->assertTrue(DB::table('invoice_reminders')->where('kind', 'due')->whereIn('invoice_id', $soon->invoices()->pluck('invoices.id'))->exists());
    }

    public function test_the_reminder_names_no_suspension_day_for_an_exempt_account(): void
    {
        $exempt = $this->service($this->client([], ['dunning_exempt' => true]), ['expires_at' => '2026-10-19']);
        $plain = $this->service($this->client(), ['expires_at' => '2026-10-19']);
        $suspendOn = new \ReflectionMethod(PaymentReminders::class, 'suspendOn');

        $this->assertNull($suspendOn->invoke(null, $exempt->invoices()->first()));
        $this->assertNotNull($suspendOn->invoke(null, $plain->invoices()->first()));
    }

    public function test_a_groups_members_get_one_renewal_invoice_per_service(): void
    {
        foreach (['credits_enabled' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        $member = $this->client([], ['separate_invoices' => true]);
        $other = $this->client();
        $product = $this->createProduct();
        foreach ([$member, $member, $other, $other] as $client) {
            Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25']);
        }

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(2, Invoice::query()->where('client_id', $member->id)->count(), 'its group says separate');
        $this->assertSame(1, Invoice::query()->where('client_id', $other->id)->count(), 'grouped by due date');
    }

    public function test_staff_set_them_on_a_new_account_and_on_a_group(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $group = ClientGroup::query()->create(['name' => 'Partners', 'discount_percent' => 0]);

        $this->livewireAs($staff)->test(EditClientGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['separate_invoices' => true, 'dunning_exempt' => true, 'no_overdue_reminders' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame([true, true, true], [$group->fresh()->separate_invoices, $group->fresh()->dunning_exempt, $group->fresh()->no_overdue_reminders]);

        $this->livewireAs($staff)->test(CreateClient::class)
            ->fillForm(['first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test', 'password' => 'secret-pass-1',
                'dunning_exempt' => true, 'no_overdue_reminders' => true])
            ->call('create')->assertHasNoFormErrors();
        $client = User::query()->where('email', 'maria@example.test')->sole()->client();
        $this->assertSame([true, true], [$client->dunning_exempt, $client->no_overdue_reminders], 'the create page keeps them too');
    }
}

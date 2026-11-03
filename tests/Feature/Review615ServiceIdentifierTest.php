<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Services\Index as ServicesIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\ServiceIdentifier;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Review615ServiceIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function service(array $attributes = [], array $properties = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create($attributes + ['client_id' => $this->owner->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => null]);
        foreach ($properties as $key => $value) {
            $service->properties()->create(['key' => $key, 'name' => $key, 'value' => $value]);
        }

        return $service->fresh();
    }

    private function login(array $permissions): User
    {
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->owner->client()->id, 'user_id' => $login->id, 'permissions' => $permissions, 'granted_at' => now()]);
        $this->actingAs($login);
        app(CurrentClient::class)->switchTo($this->owner->client()->id);

        return $login;
    }

    /** F1: the panel's login name is shown only @can('update') on the account partial; the heading now shows it to `products`. */
    public function test_f1_a_login_that_may_not_manage_the_service_is_not_shown_its_panel_username(): void
    {
        $radio = $this->service(['username' => 'radio-login'], ['centova_username' => 'radio-login']);
        $login = $this->login(['products']);
        $this->assertTrue($login->can('view', $radio), 'precondition');
        $this->assertFalse($login->can('update', $radio), 'precondition: the account partial hides the username');

        $this->livewireAs($login)->test(ServiceShow::class, ['service' => $radio])->assertDontSee('radio-login');
    }

    public function test_f1_the_services_list_for_a_view_only_login(): void
    {
        $this->service(['username' => 'radio-login'], ['centova_username' => 'radio-login']);
        $login = $this->login(['products']);

        $this->livewireAs($login)->test(ServicesIndex::class)->assertDontSee('radio-login');
    }

    /** F1: an invoices-only login (may not view the service) is shown its panel username on the invoice page and the list. */
    public function test_f1_an_invoices_only_login_is_not_shown_the_services_panel_username(): void
    {
        $radio = $this->service(['username' => 'radio-login'], ['centova_username' => 'radio-login']);
        $invoice = Invoice::factory()->create(['client_id' => $this->owner->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Radio (01/11/2026 - 30/11/2026)', 'price' => '10.00', 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $radio->id]);
        $login = $this->login(['invoices']);
        $this->assertFalse($login->can('view', $radio), 'precondition');

        $this->livewireAs($login)->test(InvoiceShow::class, ['invoice' => $invoice])->assertDontSee('radio-login');
        $this->livewireAs($login)->test(InvoicesIndex::class)->assertDontSee('radio-login');
    }

    /** F2: the invoice page reads each line's service's license, product.server and properties one line at a time. */
    public function test_f2_the_invoice_page_queries_do_not_grow_with_its_service_lines(): void
    {
        $count = function (int $lines): int {
            $invoice = Invoice::factory()->create(['client_id' => $this->owner->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            for ($i = 0; $i < $lines; $i++) {
                $service = $this->service(['dedicated_ip' => "203.0.113.{$i}"], ['centova_username' => "radio{$i}"]);
                $invoice->items()->create(['description' => "Radio {$i}", 'price' => '10.00', 'quantity' => 1,
                    'reference_type' => Service::class, 'reference_id' => $service->id]);
            }
            $component = $this->livewireAs($this->owner)->test(InvoiceShow::class, ['invoice' => $invoice]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->call('$refresh');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count(1);
        $six = $count(6);

        $this->assertLessThanOrEqual($one + 2, $six, "1 line: {$one} queries, 6 lines: {$six}");
    }

    /** F3: the suspension subject of a hosting with a dedicated IP now carries the IP; never a panel username. */
    public function test_f3_mail_subject_has_the_ip_never_the_panel_username(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
        $hosting = $this->service(['domain' => 'shop.example.gr', 'dedicated_ip' => '203.0.113.10', 'status' => 'suspended']);
        $radio = $this->service(['status' => 'suspended'], ['centova_username' => 'radio-login']);

        NotificationHelper::serverSuspendedNotification($hosting->client, $hosting);
        $this->assertStringEndsWith('shop.example.gr · 203.0.113.10', EmailLog::query()->latest('id')->value('subject'));
        NotificationHelper::serverSuspendedNotification($radio->client, $radio);
        $this->assertStringNotContainsString('radio-login', (string) EmailLog::query()->latest('id')->value('subject'));
    }

    /** F4: a line naming another address that contains this one's (a substring) hides it. */
    public function test_f4_an_ip_inside_another_is_not_said(): void
    {
        $service = $this->service(['domain' => 'shop.example.gr', 'dedicated_ip' => '10.0.0.1']);
        $item = new InvoiceItem(['description' => 'Hosting - shop.example.gr (moved from 10.0.0.15)', 'reference_type' => Service::class]);
        $item->setRelation('reference', $service);

        $this->assertSame('10.0.0.1', ServiceIdentifier::forLine($item));
    }

    /** F5: the list says the dedicated IP once, on the glance line, not under the name too. */
    public function test_f5_the_list_says_the_ip_once(): void
    {
        $this->service(['domain' => 'shop.example.gr', 'dedicated_ip' => '203.0.113.77']);

        $html = $this->livewireAs($this->owner)->test(ServicesIndex::class)->html();
        $this->assertSame(1, substr_count($html, '203.0.113.77'));
    }

    public function test_n_the_invoices_list_queries_do_not_grow_with_its_service_lines(): void
    {
        $count = function (int $invoices): int {
            for ($n = 0; $n < $invoices; $n++) {
                $invoice = Invoice::factory()->create(['client_id' => $this->owner->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
                $service = $this->service(['dedicated_ip' => "198.51.100.{$n}"], ['centova_username' => "r{$n}"]);
                $upgradeOf = $this->service(['domain' => "u{$n}.example.gr"]);
                $invoice->items()->create(['description' => "Radio {$n}", 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
            }
            $component = $this->livewireAs($this->owner)->test(InvoicesIndex::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->call('$refresh');
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            Invoice::query()->delete();

            return $q;
        };
        $one = $count(1);
        $six = $count(6);
        $this->assertLessThanOrEqual($one + 3, $six, "1 invoice: {$one}, 6: {$six}");
    }

    /** F6: the customer's own label (nickname) was shown under an invoice line; now a domain or IP replaces it. */
    public function test_f6_the_invoice_line_keeps_the_customers_own_label(): void
    {
        $service = $this->service(['domain' => 'shop.example.gr', 'label' => 'Office site']);
        $invoice = Invoice::factory()->create(['client_id' => $this->owner->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting Plus (01/11/2026 - 30/11/2026)', 'price' => '10.00', 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);

        $this->livewireAs($this->owner)->test(InvoiceShow::class, ['invoice' => $invoice])->assertSee('Office site');
    }

    /** F6: a mail named a service with no domain by its label (#592); now any IP wins over it. */
    public function test_f6_mail_keeps_the_label_of_a_service_with_an_ip_and_no_domain(): void
    {
        $vps = $this->service(['label' => 'Office VPS', 'dedicated_ip' => '203.0.113.20']);

        $this->assertStringContainsString('Office VPS', $vps->mailName);
    }
}

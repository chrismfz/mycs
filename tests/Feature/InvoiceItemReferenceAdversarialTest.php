<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #55 (AUTH-8): what an invoice line may point at. */
class InvoiceItemReferenceAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->customer = User::factory()->create();
        $this->invoice = Invoice::factory()->create(['user_id' => $this->customer->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
    }

    private function token(array $permissions = ['admin.invoice_items.create', 'admin.invoice_items.update']): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function serviceOf(User $user): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'user_id' => $user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00,
        ]);
    }

    private function line(array $reference): array
    {
        return ['invoice_id' => $this->invoice->id, 'description' => 'Line', 'price' => 50, 'quantity' => 1, ...$reference];
    }

    // ---- findings ---------------------------------------------------------------------------------------------------

    /**
     * F1: an update that sends only reference_type skips the reference_id rule ('sometimes', field absent), so the
     * stored reference_id is re-read under the new type with no owner check. Two steps: create a line with only a
     * reference_id (no type: the rule returns early), then PUT reference_type=Service.
     */
    public function test_a_type_only_update_points_a_line_at_another_customers_service(): void
    {
        $theirs = $this->serviceOf(User::factory()->create());
        $token = $this->token();

        // The dangling id the API accepted (F2, now refused): a line with a reference_id and no type, written directly.
        $id = DB::table('invoice_items')->insertGetId(['invoice_id' => $this->invoice->id, 'description' => 'Line', 'price' => 50, 'quantity' => 1,
            'reference_type' => null, 'reference_id' => $theirs->id, 'created_at' => now(), 'updated_at' => now()]); // null: MariaDB would store ''

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $id), ['reference_type' => Service::class]);

        $item = $this->invoice->items()->first();
        $this->assertFalse(
            $item !== null && $item->reference_type === Service::class && (int) $item->reference_id === $theirs->id,
            'the line now renews another customer\'s service when this invoice is paid'
        );
    }

    /**
     * F1 (variant, one step on an existing app-made line): a line on the customer's own ServiceUpgrade #N switched to
     * reference_type=Service now points at Service #N, whoever owns it.
     */
    public function test_a_type_only_update_reinterprets_the_stored_id_under_another_type(): void
    {
        $own = $this->serviceOf($this->customer);
        $theirs = $this->serviceOf(User::factory()->create());
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $own->id, 'plan_id' => $own->plan_id, 'product_id' => $own->product_id, 'invoice_id' => $this->invoice->id]);
        // Line on the customer's own upgrade, but whose id collides with the other customer's service.
        DB::table('service_upgrades')->where('id', $upgrade->id)->update(['id' => $theirs->id]);
        $item = $this->invoice->items()->create(['description' => 'Upgrade', 'price' => 5, 'quantity' => 1, 'reference_type' => ServiceUpgrade::class, 'reference_id' => $theirs->id]);

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $item), ['reference_type' => Service::class])
            ->assertUnprocessable();
    }

    /**
     * F2: a reference_id without a type is accepted and stored as a dangling id (the seed for F1); the invoice mail /
     * PDF then fails (morphTo with an empty type: "Unknown column ''").
     */
    public function test_a_reference_id_without_a_type_is_refused(): void
    {
        $theirs = $this->serviceOf(User::factory()->create());

        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_id' => $theirs->id]))
            ->assertUnprocessable();
    }

    /**
     * F3: the owner check runs only when the line is written. Moving the invoice to another customer (API invoices
     * update, or the user_id field on the EditInvoice form) keeps lines that renew the first customer's service, now paid
     * by the second customer.
     */
    public function test_moving_the_invoice_to_another_customer_keeps_lines_on_the_first_customers_service(): void
    {
        $own = $this->serviceOf($this->customer);
        $this->invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $own->id]);
        $other = User::factory()->create();

        $this->withToken($this->token(['admin.invoices.update']))
            ->putJson(route('api.v1.admin.invoices.update', $this->invoice), ['user_id' => $other->id]);

        $invoice = $this->invoice->fresh();
        $crossCustomer = $invoice->items()->where('reference_type', Service::class)
            ->whereIn('reference_id', Service::query()->where('user_id', '!=', $invoice->user_id)->select('id'))->exists();
        $this->assertFalse($crossCustomer, 'invoice of customer B renews customer A\'s service');
    }

    // ---- holds ------------------------------------------------------------------------------------------------------

    public function test_holds_class_name_variants_are_refused(): void
    {
        $own = $this->serviceOf($this->customer);
        $token = $this->token();
        foreach (['\\' . Service::class, strtolower(Service::class), 'service', 'Service', 'App\\Models\\Credit'] as $type) {
            $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => $type, 'reference_id' => $own->id]))
                ->assertUnprocessable();
        }
        $this->assertSame(0, $this->invoice->items()->count());
    }

    /** F4 (low): a non-string reference_type reaches InvoiceItemReference::rule(?string) and answers 500 (TypeError), not 422. */
    public function test_an_array_reference_type_answers_422_not_500(): void
    {
        $own = $this->serviceOf($this->customer);

        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => ['x' => Service::class], 'reference_id' => $own->id]))
            ->assertUnprocessable();
    }

    public function test_holds_empty_string_type_stores_no_reference(): void
    {
        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => '', 'reference_id' => null]))->assertSuccessful();
        $this->assertNull($this->invoice->items()->first()->reference_type);
    }

    /**
     * F2b: reference_type without reference_id passes (the reference_id closure is not implicit, so it does not run on a
     * missing field). The line is stored as Service #null and NotificationHelper crashes on $item->reference->subscription_id.
     */
    public function test_a_type_without_an_id_is_refused(): void
    {
        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => Service::class]))
            ->assertUnprocessable();
    }

    public function test_holds_upgrade_of_another_customers_service_is_refused(): void
    {
        $theirs = $this->serviceOf(User::factory()->create());
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $theirs->id, 'plan_id' => $theirs->plan_id, 'product_id' => $theirs->product_id]);

        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_id');
    }

    public function test_holds_update_id_only_uses_the_stored_type_and_checks_the_owner(): void
    {
        $own = $this->serviceOf($this->customer);
        $theirs = $this->serviceOf(User::factory()->create());
        $item = $this->invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $own->id]);

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $item), ['reference_id' => $theirs->id])
            ->assertUnprocessable();
        $this->assertSame($own->id, (int) $item->fresh()->reference_id);
    }

    public function test_holds_update_cannot_move_a_line_to_another_invoice(): void
    {
        $own = $this->serviceOf($this->customer);
        $item = $this->invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $own->id]);
        $foreign = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $item), ['invoice_id' => $foreign->id])->assertSuccessful();
        $this->assertSame($this->invoice->id, (int) $item->fresh()->invoice_id);
    }

    public function test_holds_admin_form_keeps_a_renewal_lines_reference_when_its_price_changes(): void
    {
        $own = $this->serviceOf($this->customer);
        $item = $this->invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $own->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        // A second Livewire request is bounced to auth.logout by the session middleware in tests: drive the page in-process.
        $this->actingAs($owner);
        $page = $this->livewireAs($owner)->test(EditInvoice::class, ['record' => $this->invoice->getKey()])->instance();
        $key = array_key_first($page->data['items']);
        $page->data['items'][$key]['price'] = '12.00';
        $page->data['items'][$key]['reference_type'] = Credit::class;
        $page->data['items'][$key]['reference_id'] = 999999;
        // A new line whose hidden fields the browser filled in.
        $page->data['items']['new-line'] = ['price' => '3.00', 'quantity' => 1, 'description' => 'Injected', 'reference_type' => Credit::class, 'reference_id' => 1];
        $page->save(false, false);

        $this->assertSame(0, $this->invoice->items()->where('reference_type', Credit::class)->count());
        $this->assertSame(1, $this->invoice->items()->where('description', 'Injected')->whereNull('reference_type')->count());
        $item->refresh();
        $this->assertSame('12.00', number_format((float) $item->price, 2, '.', ''));
        $this->assertSame(Service::class, $item->reference_type);
        $this->assertSame($own->id, (int) $item->reference_id);
    }
}

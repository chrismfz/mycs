<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What an invoice line may point at (audit sweep AUTH-8 / DATA-19, the review of #49 B1/B2). The API accepted any
 * class name (and built it with app()): a line on Credit sold account credit, a line on another customer's service
 * renewed it when this invoice was paid. The admin form took the line's hidden reference fields from the browser.
 */
class InvoiceItemReferenceTest extends TestCase
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

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.invoice_items.create', 'admin.invoice_items.update']]);

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

    public function test_the_api_refuses_a_line_on_credit(): void
    {
        $credit = Credit::query()->create(['user_id' => $this->customer->id, 'currency_code' => 'USD', 'amount' => 0]);

        $this->withToken($this->token())
            ->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => Credit::class, 'reference_id' => $credit->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_type');

        $this->assertSame(0, $this->invoice->items()->count());
    }

    public function test_the_api_refuses_any_other_class(): void
    {
        $this->withToken($this->token())
            ->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => User::class, 'reference_id' => $this->customer->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_type');
    }

    public function test_the_api_refuses_another_customers_service(): void
    {
        $theirs = $this->serviceOf(User::factory()->create());

        $this->withToken($this->token())
            ->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => Service::class, 'reference_id' => $theirs->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_id');
    }

    public function test_the_api_takes_the_customers_own_service_or_no_reference(): void
    {
        $own = $this->serviceOf($this->customer);
        $token = $this->token();

        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $this->line(['reference_type' => Service::class, 'reference_id' => $own->id]))->assertSuccessful();
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $this->line([]))->assertSuccessful();

        $this->assertSame(2, $this->invoice->items()->count());
    }

    public function test_the_api_cannot_repoint_a_line_at_credit(): void
    {
        $item = $this->invoice->items()->create(['description' => 'Line', 'price' => 50, 'quantity' => 1]);
        $credit = Credit::query()->create(['user_id' => $this->customer->id, 'currency_code' => 'USD', 'amount' => 0]);

        $this->withToken($this->token())
            ->putJson(route('api.v1.admin.invoice-items.update', $item), ['reference_type' => Credit::class, 'reference_id' => $credit->id])
            ->assertUnprocessable();

        $this->assertNull($item->fresh()->reference_type);
    }

    public function test_the_admin_form_does_not_take_a_lines_reference_from_the_browser(): void
    {
        $this->invoice->items()->create(['description' => 'Line', 'price' => 50, 'quantity' => 1]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $form = $this->livewireAs($owner)->test(EditInvoice::class, ['record' => $this->invoice->getKey()])->instance()->form;
        $repeater = $form->getComponent(fn ($component) => $component instanceof Repeater && $component->getName() === 'items');
        $this->assertNotNull($repeater);

        $checked = 0;
        foreach ($repeater->getItems() as $item) {
            foreach (['reference_type', 'reference_id'] as $field) {
                $component = $item->getComponent(fn ($component) => $component instanceof Hidden && $component->getName() === $field, withHidden: true);
                $this->assertNotNull($component, $field);
                $this->assertFalse($component->isDehydrated(), "$field is written from the browser");
                $checked++;
            }
        }
        $this->assertSame(2, $checked);
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/** MT-1a final review round: the findings' tests stay as regression coverage. */
class MoneyTrailFinalReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        // The admin form validates the currency against Currency, whose queries are cached (CacheableBuilder).
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function paidInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_' . $invoice->id, currency: 'USD');

        return $invoice->fresh();
    }

    private function owner(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    /** P2: the guard (InvoiceObserver::updating) answered the API with a 500, not a 422 like the user delete guard. */
    public function test_the_api_refuses_to_move_a_paid_invoice_with_422(): void
    {
        $invoice = $this->paidInvoice();
        $other = User::factory()->create();
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => ['admin.invoices.update']]);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['user_id' => $other->id])
            ->assertStatus(422);

        $this->assertSame($this->user->id, $invoice->fresh()->user_id);
    }

    /**
     * P2: the same guard in the admin edit form was an uncaught DisplayException (error page) instead of a message.
     * In its own process: after the rest of the suite the Filament/Livewire page no longer mounts (state other tests
     * leave behind in statics); a real request is always a fresh process.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_form_refuses_to_move_a_paid_invoice_with_a_message(): void
    {
        $invoice = $this->paidInvoice();
        $other = User::factory()->create();

        Livewire::actingAs($this->owner())->test(EditInvoice::class, ['record' => $invoice->getKey()]) // the id: getRouteKey() is the invoice number
            ->set('data.user_id', $other->id)
            ->call('save')
            ->assertNotified();

        $this->assertSame($this->user->id, $invoice->fresh()->user_id);
    }

    /**
     * Nit: the message said «delete or refund its payments first», but a refunded payment still blocks (and cannot be
     * deleted). Kept: a refunded payment is still money that went through this customer's invoice. The message now
     * says so; this test expects the refusal.
     */
    public function test_a_refunded_payment_still_keeps_the_invoice_with_its_customer(): void
    {
        $invoice = $this->paidInvoice();
        $other = User::factory()->create();
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 100.00);

        try {
            $invoice->update(['user_id' => $other->id]);
            $this->fail('An invoice with a refunded payment was moved.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('refunded payment', $e->getMessage());
        }

        $this->assertSame($this->user->id, $invoice->fresh()->user_id);
    }

    /** Held (verification): the EditUser delete action cancels with a notification, the customer stays. */
    public function test_the_edit_user_delete_action_refuses_with_a_notification(): void
    {
        $this->paidInvoice();

        Livewire::actingAs($this->owner())->test(EditUser::class, ['record' => $this->user->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified(__('A customer with payments or account credit history cannot be deleted.'));

        $this->assertNotNull($this->user->fresh());
    }

    /** Held (verification): a customer without money history is still deleted from the admin. */
    public function test_the_edit_user_delete_action_deletes_a_customer_without_money(): void
    {
        Livewire::actingAs($this->owner())->test(EditUser::class, ['record' => $this->user->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertNull($this->user->fresh());
    }

    /** A stale form that moves nothing and leaves the status alone does not write an old status back. */
    public function test_saving_an_unpaid_looking_copy_without_a_status_change_keeps_the_paid_status(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        $stale = $invoice->fresh(); // loaded while pending
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_stale', currency: 'USD');
        $this->assertSame('paid', $invoice->fresh()->status);

        $stale->forceFill(['due_at' => now()->addWeeks(2)])->save();

        $this->assertSame('paid', $invoice->fresh()->status);
    }
}

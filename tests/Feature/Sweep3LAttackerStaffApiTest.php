<?php

namespace Tests\Feature;

use App\Admin\Resources\ApiResource\Pages\ManageApis;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\ApiKey;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial verification, lens «l-attacker-staff-api»: per-record policy rules that a list/bulk action skips.
 */
class Sweep3LAttackerStaffApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function key(Staff $owner, array $permissions): ApiKey
    {
        return ApiKey::query()->create([
            'name' => 'owner integration',
            'permissions' => $permissions,
            'token' => hash('sha256', Str::random(40)),
            'staff_id' => $owner->id,
            'type' => 'admin',
            'ip_addresses' => [],
            'enabled' => true,
        ]);
    }

    /**
     * L-ATTACKER-STAFF-API-1. ApiKeyPolicy::delete refuses a key whose permissions the actor could not grant; the bulk
     * delete is authorized only by deleteAny, so the same staff member removes it through the toolbar.
     */
    public function test_bulk_delete_refuses_a_key_the_actor_could_not_grant(): void
    {
        $owner = Staff::factory()->admin()->create();
        $key = $this->key($owner, ['admin.invoices.update']);
        $actor = Staff::factory()->withPermissions(['admin.api_keys.viewAny', 'admin.api_keys.delete', 'admin.api_keys.deleteAny'])->create();

        $this->assertFalse($actor->can('delete', $key), 'the policy refuses this key to the actor');

        // The single-record delete holds.
        $this->livewireAs($actor)->test(ManageApis::class)->assertTableActionHidden(DeleteAction::class, $key);
        $this->assertNotNull(ApiKey::query()->find($key->id));

        $this->livewireAs($actor)->test(ManageApis::class)->callTableBulkAction('delete', [$key]);

        $this->assertNotNull(ApiKey::query()->find($key->id), 'the bulk delete removed a key the policy refuses to this actor');
    }

    /**
     * L-ATTACKER-STAFF-API-2. The list's Cancel is authorized per record, by the policy's update: staff who may only list
     * invoices do not cancel one. («Immutable Invoices», which this was found with, is gone: owner 2026-11-10.)
     */
    public function test_list_cancel_follows_the_policy_update(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);

        $customer = User::factory()->create();
        $invoice = Invoice::query()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $staff = Staff::factory()->withPermissions(['admin.invoices.viewAny'])->create();

        $this->assertFalse($staff->can('update', $invoice));
        $this->livewireAs($staff)->test(ListInvoices::class)->assertTableActionHidden('cancel', $invoice);

        $this->assertNotSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /** The fix keeps the bulk delete for a key the actor could have granted. */
    public function test_bulk_delete_still_removes_a_key_the_actor_could_grant(): void
    {
        $owner = Staff::factory()->admin()->create();
        $key = $this->key($owner, ['admin.api_keys.viewAny']);
        $actor = Staff::factory()->withPermissions(['admin.api_keys.viewAny', 'admin.api_keys.delete', 'admin.api_keys.deleteAny'])->create();

        $this->assertTrue($actor->can('delete', $key));

        $this->livewireAs($actor)->test(ManageApis::class)->callTableBulkAction('delete', [$key]);

        $this->assertNull(ApiKey::query()->find($key->id));
    }

    /** The list Cancel cancels an issued invoice for staff who may change it. */
    public function test_list_cancel_still_works_for_staff_who_may_update(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);

        $customer = User::factory()->create();
        $invoice = Invoice::query()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'x', 'periods' => 'give_up']);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    // The Admin API's payments, refunds and adjustment notes of an invoice (owner 2026-11-10, sweep 7
    // TIGHT-INVOICES-PAYMENTS-32): each route behind the key, one permission on its list, and ids of its invoice only.

    /** @return array{0: Invoice, 1: InvoiceTransaction, 2: AdjustmentNote, 3: int} an invoice with a payment (3.00 of it refunded) and a note; the refund's id */
    private function invoiceWithMoney(): array
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $invoice = Invoice::query()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Setup', 'price' => '20.00', 'quantity' => 1, 'taxed' => false]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'tr_' . $invoice->id);
        ExtensionHelper::refundLocally($payment, 3.00, notify: false);
        $note = $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => '-1.00', 'description' => 'Goodwill']);

        return [$invoice, $payment->fresh(), $note, UndoRefund::refunds($payment)->sole()->id];
    }

    /**
     * Every new route with what it takes, on $invoice's own records.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> method, url, body, the permission it needs
     */
    private function moneyRoutes(Invoice $invoice, InvoiceTransaction $payment, AdjustmentNote $note, int $refund): array
    {
        $p = fn (string $name, array $more = []) => route('api.v1.admin.invoices.' . $name, [$invoice, ...$more]);

        return [
            ['getJson', $p('payments.index'), [], 'admin.invoice_transactions.view'],
            ['getJson', $p('payments.show', [$payment]), [], 'admin.invoice_transactions.view'],
            ['postJson', $p('payments.store'), ['amount' => '1.00'], 'admin.invoice_transactions.create'],
            ['putJson', $p('payments.update', [$payment]), ['fee' => '0.10'], 'admin.invoice_transactions.delete'],
            ['deleteJson', $p('payments.destroy', [$payment]), [], 'admin.invoice_transactions.delete'],
            ['getJson', $p('payments.refunds.index', [$payment]), [], 'admin.invoice_transactions.view'],
            ['postJson', $p('payments.refunds.store', [$payment]), ['amount' => '1.00', 'method' => 'manual', 'notify' => false], 'admin.invoice_transactions.update'],
            ['deleteJson', $p('payments.refunds.destroy', [$payment, $refund]), [], 'admin.invoice_transactions.update'],
            ['getJson', $p('adjustment-notes.index'), [], 'admin.adjustment_notes.view'],
            ['postJson', $p('adjustment-notes.store'), ['amount' => '-1.00', 'description' => 'x'], 'admin.adjustment_notes.create'],
            ['putJson', $p('adjustment-notes.update', [$note]), ['description' => 'y'], 'admin.adjustment_notes.update'],
            ['postJson', $p('adjustment-notes.void', [$note]), [], 'admin.adjustment_notes.update'],
            ['postJson', $p('adjustment-notes.unvoid', [$note]), [], 'admin.adjustment_notes.update'],
            ['deleteJson', $p('adjustment-notes.destroy', [$note]), [], 'admin.adjustment_notes.delete'],
        ];
    }

    /** @return array<string, mixed> what a refused request must leave as it was */
    private function moneySnapshot(): array
    {
        return [
            'payments' => InvoiceTransaction::query()->orderBy('id')->get(['id', 'invoice_id', 'amount', 'fee', 'refunded_amount', 'credited_amount'])->toArray(),
            'notes' => AdjustmentNote::query()->orderBy('id')->get(['id', 'invoice_id', 'amount', 'description', 'status'])->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->pluck('status', 'id')->all(),
            'events' => MoneyEvent::query()->count(),
        ];
    }

    private function bearer(Staff $owner, array $permissions, string $type = 'admin'): string
    {
        $token = 'ATK' . Str::random(40);
        ApiKey::query()->create(['name' => 'attacker', 'permissions' => $permissions, 'token' => hash('sha256', $token), 'staff_id' => $owner->id,
            'type' => $type, 'ip_addresses' => [], 'enabled' => true]);

        return $token;
    }

    /** L-ATTACKER-STAFF-API-3. No key, a disabled one, or a key of another scope (not an admin key): nothing, on any new route. */
    public function test_the_money_routes_answer_only_an_admin_key(): void
    {
        [$invoice, $payment, $note, $refund] = $this->invoiceWithMoney();
        $admin = Staff::factory()->admin()->create();
        $all = array_values(array_unique(array_column($this->moneyRoutes($invoice, $payment, $note, $refund), 3)));
        $other = $this->bearer($admin, $all, 'client');
        $disabled = $this->bearer($admin, $all);
        ApiKey::query()->where('token', hash('sha256', $disabled))->update(['enabled' => false]);
        $before = $this->moneySnapshot();

        foreach ($this->moneyRoutes($invoice, $payment, $note, $refund) as [$method, $url, $body]) {
            $this->{$method}($url, $body)->assertUnauthorized();
            $this->withToken($disabled)->{$method}($url, $body)->assertUnauthorized();
            $this->withToken($other)->{$method}($url, $body)->assertForbidden();
            $this->flushHeaders();
        }

        $this->assertSame($before, $this->moneySnapshot());
        // Nothing of it under the client API or anywhere outside the admin group.
        $outside = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => preg_match('#payments|refunds|adjustment-notes#', $route->uri()) && str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/') && in_array('api.admin', $route->gatherMiddleware(), true)
                && in_array('throttle:admin-api', $route->gatherMiddleware(), true));
        $this->assertSame([], $outside->map->uri()->values()->all());
    }

    /**
     * L-ATTACKER-STAFF-API-4. A key holding every permission of these routes but the one a route needs is refused there
     * (403) and changes nothing; so is a key whose owner lost the role permission behind it.
     */
    public function test_each_money_route_needs_its_own_permission_on_the_key(): void
    {
        [$invoice, $payment, $note, $refund] = $this->invoiceWithMoney();
        $admin = Staff::factory()->admin()->create();
        $routes = $this->moneyRoutes($invoice, $payment, $note, $refund);
        $all = [...array_values(array_unique(array_column($routes, 3))), 'admin.invoices.update', 'admin.invoices.view', 'admin.invoice_items.update'];
        $before = $this->moneySnapshot();

        foreach ($routes as [$method, $url, $body, $needs]) {
            $this->withToken($this->bearer($admin, array_values(array_diff($all, [$needs]))))->{$method}($url, $body)->assertForbidden();
            $this->flushHeaders();
        }
        // The list on the key is bounded by its owner's role today (AdminApi): a role without payments, a key that lists them.
        $clerk = Staff::factory()->withPermissions(['admin.invoices.viewAny', 'admin.invoices.update'])->create();
        $token = $this->bearer($clerk, $all);
        foreach ($routes as [$method, $url, $body]) {
            $this->withToken($token)->{$method}($url, $body)->assertForbidden();
        }

        $this->assertSame($before, $this->moneySnapshot());
    }

    /** L-ATTACKER-STAFF-API-5. A payment, a refund or a note of ANOTHER invoice under this invoice's URL: 404, nothing changed. */
    public function test_a_money_route_takes_only_ids_of_the_invoice_in_its_url(): void
    {
        [$invoice, $payment, $note, $refund] = $this->invoiceWithMoney();
        [$other, $otherPayment, $otherNote, $otherRefund] = $this->invoiceWithMoney();
        $admin = Staff::factory()->admin()->create();
        $token = $this->bearer($admin, array_values(array_unique(array_column($this->moneyRoutes($invoice, $payment, $note, $refund), 3))));
        $before = $this->moneySnapshot();

        // The other invoice's payment and note under this invoice.
        foreach ($this->moneyRoutes($invoice, $otherPayment, $otherNote, $otherRefund) as [$method, $url, $body]) {
            if (str_contains($url, '/payments/') || str_contains($url, '/adjustment-notes/')) {
                $this->withToken($token)->{$method}($url, $body)->assertNotFound();
            }
        }
        // This invoice's payment with the other payment's refund.
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [$invoice, $payment, $otherRefund]))->assertNotFound();
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [$invoice, $payment, 0]))->assertNotFound();
        // An edit never moves a payment to another customer's invoice.
        $this->withToken($this->bearer($admin, ['admin.invoice_transactions.delete', 'admin.invoice_transactions.create']))
            ->putJson(route('api.v1.admin.invoices.payments.update', [$invoice, $payment]), ['invoice_id' => $other->id])->assertStatus(422);

        $this->assertSame($before, $this->moneySnapshot());
    }
}

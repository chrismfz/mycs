<?php

namespace Tests\Feature;

use App\Admin\Resources\ApiResource\Pages\ManageApis;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\ApiKey;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
     * L-ATTACKER-STAFF-API-2. With immutable invoices on, InvoicePolicy::update allows only a draft, and ViewInvoice's
     * Cancel follows it; the list's Cancel checks only the class-level permission and cancels an issued invoice.
     */
    public function test_list_cancel_follows_the_immutable_invoice_rule(): void
    {
        Setting::query()->updateOrCreate(['key' => 'immutable_invoices_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        config(['settings.immutable_invoices_enabled' => true]);
        Cache::forget('settings.rows');
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);

        $customer = User::factory()->create();
        $invoice = Invoice::query()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $staff = Staff::factory()->admin()->create();

        $this->assertFalse($staff->can('update', $invoice), 'the policy keeps an issued invoice as it is');

        // ViewInvoice holds.
        $this->livewireAs($staff)->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertActionHidden('cancel');

        $this->livewireAs($staff)->test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'x', 'periods' => 'give_up']);

        $this->assertNotSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'the list cancelled an immutable invoice');
    }
}

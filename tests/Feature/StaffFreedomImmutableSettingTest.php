<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Classes\Settings;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 7 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-31): «Immutable Invoices» is gone,
 * the setting, its stored row and the read-only second invoice page. docs/staff-freedom.md.
 */
class StaffFreedomImmutableSettingTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_setting_is_gone_and_its_stored_row_is_removed(): void
    {
        $names = collect(Settings::settings())->flatten(1)->pluck('name');
        $this->assertNotContains('immutable_invoices_enabled', $names->all());
        $this->assertContains('mass_pay_enabled', $names->all(), 'the list is read');

        Setting::query()->updateOrCreate(['key' => 'immutable_invoices_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        Setting::query()->updateOrCreate(['key' => 'mass_pay_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        (require database_path('migrations/2027_02_20_000000_remove_immutable_invoices_setting.php'))->up();

        $this->assertSame(0, DB::table('settings')->where('key', 'immutable_invoices_enabled')->count());
        $this->assertSame(1, DB::table('settings')->where('key', 'mass_pay_enabled')->count(), 'only its own row');
    }

    /** A value left in the config (an old cache) changes nothing: a paid imported invoice opens on its edit page and saves. */
    public function test_a_paid_imported_invoice_is_edited_whatever_the_old_setting_said(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($client->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(501, 'Paid', [31 => 10.0]);
        $this->importWhmcs();
        $invoice = $this->imported(501);
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->assertTrue($staff->can('update', $invoice), 'the policy asks only for the permission');
        $this->assertArrayNotHasKey('view', InvoiceResource::getPages());
        $due = '2026-09-20';
        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertOk()->assertNoRedirect()
            ->set('data.due_at', $due)->call('save')->assertHasNoFormErrors()->assertNotNotified(__('Invoice not saved'));

        $fresh = $invoice->fresh();
        $this->assertSame($due, $fresh->due_at->toDateString());
        $this->assertSame(Invoice::STATUS_PAID, $fresh->status);
        $this->assertSame('10.00', number_format((float) $fresh->total, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $fresh->remaining, 2, '.', ''), 'its money is as imported');
    }
}

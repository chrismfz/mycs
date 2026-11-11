<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Adversarial review of PR #84 (AUTH-2/AUTH-3). Mapping InvoicePolicy::view() from the undefined «admin.invoices.view»
 * to «admin.invoices.viewAny» opens ViewInvoice (immutable invoices) to staff who could only list invoices. Before,
 * only «*» could open it. Its «Cancel Invoice» header action has visible() but no authorize(): a list-only role now
 * cancels invoices (writes off what the customer owes).
 */
class PermissionReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /** R1: a role with only «View Invoices» cancels an unpaid invoice (the list's Cancel; the view page is gone, 2026-11-10). */
    public function test_list_only_staff_cannot_cancel_an_invoice(): void
    {
        $viewer = $this->staff(['admin.invoices.viewAny']);
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        $this->actingAs($viewer);
        try {
            Livewire::test(ListInvoices::class)->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'not mine to cancel']);
        } catch (\Throwable) {
            // a refusal (403 / hidden action) is the expected outcome
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, '«View Invoices» alone cancelled the invoice');
    }

    /**
     * R2: the converse of PermissionNamesTest. The PR adds «admin.audit.viewAny» and «admin.notification_templates.*»
     * next to the existing «admin.audits.viewAny» («View Audits») and «admin.email_templates.*», which nothing checks.
     * The role editor shows both sets; ticking «View Audits» or «View Email Templates» grants nothing.
     */
    public function test_every_defined_permission_is_checked_somewhere(): void
    {
        $code = '';
        foreach ((new Finder)->in([app_path(), base_path('extensions'), resource_path('views')])->name('*.php')->files() as $file) {
            $code .= $file->getContents();
        }
        $unused = array_values(array_filter(array_keys(Arr::dot(config('permissions.role'))),
            fn (string $name): bool => !str_contains($code, "'{$name}'")));

        $this->assertSame([], $unused, 'defined (a checkbox in the role editor) but never checked: grants nothing');
    }
}

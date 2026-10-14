<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Independent code review of PR #65 (invoice route binding). Both failed on 5ffe3430; fixed in the next commit.
 */
class InvoiceRouteBindingCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(?string $number): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => $number]);

        return $invoice->fresh();
    }

    /**
     * F1: InvoiceResource builds its URLs with «{record:id}», but Filament re-resolves the record on every Livewire request
     * (Resources\Pages\Page::resolveRecordFromLivewire) from $model->getRouteKey() because getRecordRouteKeyName() is
     * null. For an invoice with a formatted number (nixpal «2026-15», Paymenter's default «INV-…») that is the number, which
     * is then looked up as an id: the page opens, and its first action/save (mark paid, refund, delete payment…) is a 404
     * (on MariaDB '2026-15' is cast to 2026: a different invoice id, still a 404 by the key check). Pre-existing on main,
     * left in place by this PR. Fix: `protected static ?string $recordRouteKeyName = 'id';` in InvoiceResource.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_edit_page_of_an_invoice_with_a_formatted_number_survives_a_livewire_request(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $invoice = $this->invoice('2026-15');

        $this->livewireAs($owner)->test(EditInvoice::class, ['record' => $invoice->id])
            ->assertOk()
            ->call('$refresh')
            ->assertOk();
    }

    /**
     * F2: a non-numeric number is put in the URL verbatim, and Laravel does not encode «/», «#», «?» in route
     * parameters. A number such as «INV/2026/15» (invoice_number_format is free text; imported WHMCS invoicenum too)
     * gives a URL that matches no route.
     */
    public function test_a_number_with_a_slash_still_gives_a_working_url(): void
    {
        $invoice = $this->invoice('INV/2026/15');

        $url = route('invoices.show', $invoice);
        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
        } catch (NotFoundHttpException) {
            $this->fail("{$url} matches no route");
        }
        $this->assertSame('invoices.show', $route->getName());
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * INV-17 (audit sweep): «Issued At» of the invoice form was shown and never saved. Now as WHMCS's «Invoice Date»: today
 * or before, while the invoice is a draft or unpaid; the due date never before it; never fillable by other paths.
 */
class InvoiceIssuedAtTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 15:30:00');
        Mail::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $this->customer = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(array $form)
    {
        $undo = Repeater::fake();
        $page = $this->livewireAs($this->staff)->test(CreateInvoice::class)->fillForm($form + [
            'client_id' => $this->customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'send_email' => false,
            'items' => [['price' => '10.00', 'quantity' => 1, 'description' => 'Hosting']],
        ])->call('create');
        $undo();

        return $page;
    }

    public function test_a_new_invoice_keeps_the_date_staff_chose(): void
    {
        $this->create(['created_at' => '2026-10-01', 'due_at' => '2026-10-08'])->assertHasNoFormErrors();

        $this->assertSame('2026-10-01 00:00:00', Invoice::query()->sole()->created_at->toDateTimeString());
    }

    public function test_today_keeps_its_time_and_a_later_day_is_refused(): void
    {
        $this->create(['created_at' => '2026-10-23', 'due_at' => '2026-10-30'])->assertHasNoFormErrors();
        $this->assertSame('2026-10-23 15:30:00', Invoice::query()->sole()->created_at->toDateTimeString());

        $this->create(['created_at' => '2026-10-25', 'due_at' => '2026-10-30'])->assertHasFormErrors(['created_at']);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_the_due_date_is_never_before_it(): void
    {
        $this->create(['created_at' => '2026-10-10', 'due_at' => '2026-10-05'])->assertHasFormErrors(['due_at']);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_an_unpaid_invoice_changes_it_a_paid_one_keeps_it(): void
    {
        $invoice = Invoice::query()->create(['client_id' => $this->customer->client()->id, 'currency_code' => 'USD', 'due_at' => '2026-10-30', 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Hosting', 'price' => 10, 'quantity' => 1]);

        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->fillForm(['created_at' => '2026-10-02'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2026-10-02', $invoice->fresh()->created_at->toDateString());

        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertFormFieldIsDisabled('created_at')
            ->set('data.created_at', '2026-09-01')->call('save');
        $this->assertSame('2026-10-02', $invoice->fresh()->created_at->toDateString(), 'reports count a paid one in its year');
    }

    public function test_no_other_path_fills_it(): void
    {
        $invoice = new Invoice(['client_id' => $this->customer->client()->id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID, 'created_at' => '2020-01-01']);

        $this->assertNull($invoice->created_at);
    }
}

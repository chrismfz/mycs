<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Events\Invoice\Finalized;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR E (decision 8, sweep Π2.1–Π2.6): staff merge unpaid invoices of a customer into one, or split lines off to a new
 * one. Lines move (never copied) under the customer → invoices locks, with their tax as issued; an invoice with payments
 * is refused. A merged invoice is kept, cancelled, «merged into #X».
 */
class InvoiceMergeSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]); // one renewal invoice per service, to merge
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return Collection<int, Invoice> */
    private function renewalInvoices(?Service $of = null): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)
            ->when($of, fn ($q) => $q->where('reference_id', $of->id)))->with('items')->orderBy('id')->get();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** @return array{0: Invoice, 1: Invoice} two renewal invoices of the customer, due the 25th and the 26th */
    private function two(): array
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->cron();
        [$a, $b] = $this->renewalInvoices()->all();

        return [$a, $b];
    }

    public function test_merge_moves_the_lines_onto_the_oldest_and_cancels_the_other_as_merged(): void
    {
        [$a, $b] = $this->two();
        $notes = AdjustmentNote::query()->count();

        $target = (new MergeInvoices)->handle($a->id, [$b->id]);

        $this->assertSame($a->id, $target->id);
        $this->assertCount(2, $target->items);
        $this->assertSame('25.00', $this->money($target->total));
        $this->assertSame('2026-10-25', $target->due_at->toDateString());
        $b->refresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $b->status);
        $this->assertSame($a->id, $b->merged_into_id);
        $this->assertStringContainsString('#' . ($a->number ?: $a->id), (string) $b->cancellation_reason);
        $this->assertCount(0, $b->items);
        $this->assertSame($notes, AdjustmentNote::query()->count(), 'no credit note for a merged invoice: it bills nothing');
    }

    public function test_the_target_takes_the_earliest_due_date(): void
    {
        [$a, $b] = $this->two();

        $target = (new MergeInvoices)->handle($b->id, [$a->id]);

        $this->assertSame($b->id, $target->id);
        $this->assertSame('2026-10-25', $target->due_at->toDateString(), 'a merge never delays a suspension');
    }

    public function test_paying_the_merged_invoice_renews_both_and_the_cron_bills_nothing_again(): void
    {
        [$a, $b] = $this->two();
        (new MergeInvoices)->handle($a->id, [$b->id]);

        (new RecordPaymentService)->handle($a->fresh(), null, 25.0, transactionId: 'wire-m');

        $this->assertSame(['2026-11-25', '2026-11-26'], Service::query()->orderBy('id')->get()->map(fn (Service $s) => $s->expires_at->toDateString())->all());
        $this->assertSame(0, InvoiceItem::query()->whereNotNull('forgone_at')->count(), 'the merged invoice gave up on no period (Δ3)');
        $this->cron();
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
    }

    public function test_two_periods_of_one_service_merged_renew_it_twice(): void
    {
        $service = $this->service();
        $this->cron(); // Oct 25 → Nov 25
        Carbon::setTestNow('2026-11-20 10:00:00');
        $service->forceFill(['expires_at' => '2026-11-25'])->saveQuietly(); // as if the first had been paid late, still open
        $this->cron(); // Nov 25 → Dec 25
        [$first, $second] = $this->renewalInvoices($service)->all();
        $service->forceFill(['expires_at' => '2026-10-25'])->saveQuietly();

        $target = (new MergeInvoices)->handle($first->id, [$second->id]);
        (new RecordPaymentService)->handle($target, null, 20.0, transactionId: 'wire-2');

        $this->assertSame('2026-12-25', $service->fresh()->expires_at->toDateString(), 'each line renews its own period');
    }

    public function test_a_line_keeps_its_tax_as_issued(): void
    {
        [$a, $b] = $this->two();
        $before = $b->items->sole()->only(['tax_rate', 'tax_amount', 'line_total']);
        config(['settings.tax_enabled' => true]); // the tax in force changes meanwhile

        (new MergeInvoices)->handle($a->id, [$b->id]);

        $moved = InvoiceItem::query()->whereKey($b->items->sole()->id)->sole();
        $this->assertSame($a->id, (int) $moved->invoice_id);
        $this->assertEquals($before, $moved->only(['tax_rate', 'tax_amount', 'line_total']));
    }

    public function test_an_invoice_with_a_payment_is_not_merged_and_nothing_moves(): void
    {
        [$a, $b] = $this->two();
        (new RecordPaymentService)->handle($b, null, 5.0, transactionId: 'part');

        try {
            (new MergeInvoices)->handle($a->id, [$b->id]);
            $this->fail('merged an invoice with a payment');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
        }

        $this->assertSame([1, 1], [$a->items()->count(), $b->items()->count()]);
        $this->assertSame(Invoice::STATUS_UNPAID, $b->fresh()->status);
    }

    public function test_another_customer_or_currency_is_refused(): void
    {
        $this->service();
        $other = User::factory()->create();
        $other->client()->update(['separate_invoices' => true]);
        $this->service([], $other);
        $this->service(['currency_code' => 'EUR', 'expires_at' => '2026-10-24']);
        $this->cron();
        [$mine, $theirs, $euro] = [
            $this->renewalInvoices()->firstWhere('client_id', $this->user->client()->id),
            $this->renewalInvoices()->firstWhere('client_id', $other->client()->id),
            $this->renewalInvoices()->firstWhere('currency_code', 'EUR'),
        ];

        foreach ([$theirs, $euro] as $source) {
            try {
                (new MergeInvoices)->handle($mine->id, [$source->id]);
                $this->fail('merged across customers or currencies');
            } catch (DisplayException) {
            }
        }
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_CANCELLED)->count());
    }

    public function test_invoices_billed_under_different_details_are_not_merged(): void
    {
        [$a, $b] = $this->two();
        $b->snapshot()->update(['tax_country' => 'DE', 'tax_rate' => 19]);

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($a->id, [$b->id]);
    }

    public function test_a_merged_invoice_is_not_reopened(): void
    {
        [$a, $b] = $this->two();
        (new MergeInvoices)->handle($a->id, [$b->id]);

        $this->expectException(DisplayException::class);
        $b->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
    }

    public function test_split_moves_the_chosen_lines_to_a_new_invoice(): void
    {
        [$a, $b] = $this->two();
        $target = (new MergeInvoices)->handle($a->id, [$b->id]);
        $line = $target->items->firstWhere('reference_id', Service::query()->orderByDesc('id')->value('id'));
        Event::fake([Finalized::class]);

        $new = (new SplitInvoice)->handle($target->id, [$line->id]);

        $this->assertSame($target->id, $new->split_from_id);
        $this->assertSame([Invoice::STATUS_UNPAID, '2026-10-25', 'USD'], [$new->status, $new->due_at->toDateString(), $new->currency_code]);
        $this->assertSame([$line->id], $new->items->pluck('id')->all());
        $this->assertSame(['10.00', '15.00'], [$this->money($target->fresh()->total), $this->money($new->total)]);
        $this->assertSame($target->snapshot->only(['name', 'tax_country']), $new->snapshot->only(['name', 'tax_country']));
        Event::assertNotDispatched(Finalized::class, fn (Finalized $event) => $event->sendEmail);
    }

    public function test_split_keeps_at_least_one_line_and_refuses_with_payments(): void
    {
        [$a, $b] = $this->two();
        $target = (new MergeInvoices)->handle($a->id, [$b->id]);

        try {
            (new SplitInvoice)->handle($target->id, $target->items->pluck('id')->all());
            $this->fail('split every line off');
        } catch (DisplayException) {
        }
        (new RecordPaymentService)->handle($target->fresh(), null, 5.0, transactionId: 'part');
        try {
            (new SplitInvoice)->handle($target->id, [$target->items->first()->id]);
            $this->fail('split an invoice with a payment');
        } catch (DisplayException) {
        }

        $this->assertSame(2, $target->items()->count());
        $this->assertSame(0, Invoice::query()->whereNotNull('split_from_id')->count());
    }

    public function test_a_line_of_another_invoice_is_not_split_off(): void
    {
        [$a, $b] = $this->two();
        $this->service(['expires_at' => '2026-10-25']);
        $this->cron();
        $third = $this->renewalInvoices()->last();
        (new MergeInvoices)->handle($a->id, [$third->id]);

        $this->expectException(DisplayException::class);
        (new SplitInvoice)->handle($a->id, [$b->items->sole()->id]);
    }

    public function test_staff_merge_from_the_list_and_split_from_the_invoice(): void
    {
        [$a, $b] = $this->two();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(ListInvoices::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target' => $a->id])
            ->assertHasNoActionErrors();
        $this->assertSame($a->id, $b->fresh()->merged_into_id);

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $a->id])
            ->callAction('split', data: ['lines' => [(string) $a->items()->orderBy('id')->value('id')]])
            ->assertHasNoActionErrors();
        $this->assertSame(1, Invoice::query()->where('split_from_id', $a->id)->count());
    }
}

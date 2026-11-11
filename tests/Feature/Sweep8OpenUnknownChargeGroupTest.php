<?php

namespace Tests\Feature;

use App\Admin\Actions\ResolveCharge;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Payment\SavedMethodCharge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * Open item of the sweep-8 reviews (#791): a charge of unknown outcome is sent again with its key only for the same thing,
 * the same invoice AND the same Mass Pay group (none matching none). An invoice's own unknown charge is not resent as a
 * group's charge of the same total, nor a group's as the invoice's own: the gateway keeps the key for the first request
 * (Stripe: 400 idempotency_error on other parameters). The other charge is refused before anything is sent, as any payment
 * of a held invoice is (SavedMethodCharge::onItsWay): never with the old key, never with a new one.
 */
class Sweep8OpenUnknownChargeGroupTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $card;

    /** @var list<array{key: string|null, group: string|null}> the charges that reached Stripe */
    private array $sent = [];

    private string $mode = 'timeout';

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.mass_pay_enabled' => true]);
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->client = User::factory()->create()->client();
        $stripe = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $stripe->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/customers/')) {
                return Http::response(['id' => 'cus_1']);
            }
            $this->sent[] = ['key' => $request->header('Idempotency-Key')[0] ?? null, 'group' => $request->data()['metadata']['payment_group'] ?? null];

            return $this->mode === 'timeout' ? throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response(['id' => 'pi_1', 'status' => 'succeeded']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $amount, string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'price' => $amount, 'quantity' => 1]);

        return $invoice->fresh();
    }

    /** Staff changed the invoice's one line since. */
    private function reprice(Invoice $invoice, string $amount): void
    {
        $invoice->items->each->update(['price' => $amount]);
        $this->assertSame($amount, number_format((float) $invoice->fresh()->remaining, 2, '.', ''));
    }

    private function charge(Invoice $invoice, ?PaymentGroup $group = null): ?ChargeAttempt
    {
        try {
            return (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, $group === null ? ChargeAttempt::SOURCE_CLIENT : ChargeAttempt::SOURCE_GROUP, $group?->fresh())->fresh();
        } catch (DisplayException $refused) {
            $this->assertSame('A payment for this invoice is already on its way.', $refused->getMessage());

            return null;
        }
    }

    /** (a) An unknown charge of invoice A alone, then a group led by A of the same total: nothing is sent, A stays held. */
    public function test_an_invoices_own_unknown_charge_is_not_resent_as_a_groups_charge(): void
    {
        $a = $this->invoice('10.00', '2026-11-09');
        $b = $this->invoice('4.00', '2026-11-10');
        $own = $this->charge($a);
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, null, '10.00'], [$own->status, $own->payment_group_id, $own->amount]);

        // A group of the same total, led by A: as one chosen before the charge (a held invoice cannot be chosen now).
        $this->reprice($a, '6.00');
        try {
            (new MassPay)->open($this->client, [$a->id, $b->id]);
            $this->fail('a held invoice is not chosen for Mass Pay');
        } catch (DisplayException) {
        }
        $group = PaymentGroup::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'invoice_id' => $a->id,
            'total' => '10.00', 'status' => PaymentGroup::STATUS_OPEN]);
        $group->invoices()->attach($a->id, ['position' => 0, 'amount' => '6.00']);
        $group->invoices()->attach($b->id, ['position' => 1, 'amount' => '4.00']);

        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-11 09:20:00');
        $this->assertNull($this->charge($a, $group), 'refused before anything is sent');

        $this->assertCount(1, $this->sent, 'nothing sent: not with A\'s key, not with a new one');
        $this->assertSame(1, ChargeAttempt::query()->count());
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, $own->key, null], [$own->fresh()->status, $own->fresh()->key, $own->fresh()->payment_group_id]);
        $this->assertTrue(SavedMethodCharge::onItsWay($a->fresh()), 'A is still held by its own charge');
    }

    /** (b) An unknown charge of a group, then its lead invoice alone for the same total: nothing is sent, the group's hold stands. */
    public function test_a_groups_unknown_charge_is_not_resent_as_its_lead_invoices_own_charge(): void
    {
        $a = $this->invoice('6.00', '2026-11-09');
        $b = $this->invoice('4.00', '2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $ofGroup = $this->charge($a, $group);
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, $group->id, '10.00', $a->id], [$ofGroup->status, $ofGroup->payment_group_id, $ofGroup->amount, $ofGroup->invoice_id]);
        $this->assertSame($group->ulid, $this->sent[0]['group']);

        $this->reprice($a, '10.00'); // the lead alone now owes the group's total
        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-11 09:20:00');
        $this->assertNull($this->charge($a), 'refused before anything is sent');
        // The group's other invoice was refused before this fix too: the group's unknown charge holds it (pinned).
        $this->assertNull($this->charge($b));

        $this->assertCount(1, $this->sent, 'nothing sent: not with the group\'s key, not with a new one');
        $this->assertSame(1, ChargeAttempt::query()->count());
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, $ofGroup->key, $group->id], [$ofGroup->fresh()->status, $ofGroup->fresh()->key, $ofGroup->fresh()->payment_group_id]);
        $this->assertTrue(SavedMethodCharge::onItsWay($a->fresh()));
        $this->assertTrue(SavedMethodCharge::onItsWay($b->fresh()));
    }

    /**
     * REV-801-1: the refusal does not lean on the hold, which leaves out an unknown attempt it takes for recorded (a payment
     * of at least its amount on the invoice earlier the same day), nor on the key's window: a group's unknown charge
     * refuses the lead's own charge the next day too. After staff «Resolve charge» it (not taken), the new charge goes out.
     */
    public function test_another_things_unknown_charge_refuses_past_the_key_window_until_staff_resolve_it(): void
    {
        $a = $this->invoice('6.00', '2026-11-09');
        $b = $this->invoice('4.00', '2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $ofGroup = $this->charge($a, $group);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $ofGroup->status);

        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-12 10:00:00'); // past Stripe's 23 hours
        $this->assertNull($this->charge($a));
        $this->assertCount(1, $this->sent);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $a->getRouteKey()])
            ->callAction('resolveCharge', ['attempt' => (string) $ofGroup->id, 'outcome' => ResolveCharge::NOT_TAKEN])->assertHasNoActionErrors();
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $ofGroup->fresh()->status);

        $own = $this->charge($a);
        $this->assertSame([ChargeAttempt::STATUS_SUCCEEDED, null, '6.00'], [$own->status, $own->payment_group_id, $own->amount]);
        $this->assertCount(2, $this->sent);
        $this->assertNotSame($ofGroup->key, $this->sent[1]['key'], 'a new charge, with its own key');
    }

    /** (c) The same thing again is still resent with its key: an invoice's own charge, and a group's charge. */
    public function test_the_same_charge_is_still_resent_with_its_key(): void
    {
        $single = $this->invoice('10.00', '2026-11-08');
        $first = $this->charge($single);
        Carbon::setTestNow('2026-11-11 09:20:00');
        $again = $this->charge($single);
        $this->assertSame($first->id, $again->id);
        $this->assertSame([$first->key, $first->key], array_column($this->sent, 'key'));

        $this->sent = [];
        $a = $this->invoice('6.00', '2026-11-09');
        $b = $this->invoice('4.00', '2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $ofGroup = $this->charge($a, $group);
        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-11 09:40:00');
        $resent = $this->charge($a, $group);
        $this->assertSame($ofGroup->id, $resent->id);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $resent->status);
        $this->assertSame([['key' => $ofGroup->key, 'group' => $group->ulid], ['key' => $ofGroup->key, 'group' => $group->ulid]], $this->sent);
        $this->assertSame(2, ChargeAttempt::query()->count());
    }
}

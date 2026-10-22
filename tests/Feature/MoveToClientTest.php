<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\ClientNote;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A service or a domain moved to another client by staff (owner 2026-10-23, as WHMCS's «Move»): its unpaid invoices go
 * with it (whole when they bill only it, split when they bill other items too), paid ones stay, one with a payment
 * refuses; a service's related contact is cleared, a subscription refuses; a domain's contacts are copied, nothing
 * changes at the registrar; a note on both accounts.
 */
class MoveToClientTest extends TestCase
{
    use RefreshDatabase;

    private User $from;

    private User $to;

    private Service $service;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->from = User::factory()->create();
        $this->to = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 10, 'expires_at' => now()->addMonth()]);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    private function invoice(User $owner, array $lines, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $owner->client()->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['quantity' => 1, 'price' => 10, 'description' => 'Line']);
        }
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
        }

        return $invoice->fresh();
    }

    private function serviceLine(array $extra = []): array
    {
        return $extra + ['reference_type' => Service::class, 'reference_id' => $this->service->id];
    }

    public function test_the_service_moves_with_the_unpaid_invoice_that_bills_only_it(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine()]);
        $paid = $this->invoice($this->from, [$this->serviceLine()], Invoice::STATUS_PAID);

        $result = app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertSame([[$invoice->id], []], [$result['moved'], $result['split']]);
        $this->assertSame($this->to->client()->id, $this->service->fresh()->client_id);
        $this->assertSame($this->to->client()->id, $invoice->fresh()->client_id, 'moved whole, same invoice');
        $this->assertSame($this->from->client()->id, $paid->fresh()->client_id, 'a paid one stays (its history)');
        $this->assertSame(1, ClientNote::query()->where('client_id', $this->from->client()->id)->count());
        $this->assertSame(1, ClientNote::query()->where('client_id', $this->to->client()->id)->count());
    }

    public function test_an_invoice_that_bills_other_items_too_is_split(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 5]);
        $invoice = $this->invoice($this->from, [$this->serviceLine(), ['reference_type' => Service::class, 'reference_id' => $other->id, 'price' => 5]]);

        $result = app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $new = Invoice::query()->findOrFail($result['split'][$invoice->id]);
        $this->assertSame([$this->from->client()->id, [$other->id]], [$invoice->fresh()->client_id, $invoice->items()->pluck('reference_id')->all()]);
        $this->assertSame([$this->to->client()->id, [$this->service->id], 'EUR'], [$new->client_id, $new->items()->pluck('reference_id')->all(), $new->currency_code]);
        $this->assertSame('10.00', number_format((float) $new->items()->sole()->price, 2, '.', ''));
    }

    public function test_an_invoice_with_a_payment_refuses_the_move_and_nothing_moves(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine()]);
        $invoice->transactions()->create(['amount' => 3]);

        $this->assertInstanceOf(DisplayException::class, rescue(fn () => app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff), fn ($e) => $e, false));

        $this->assertSame($this->from->client()->id, $this->service->fresh()->client_id);
        $this->assertSame($this->from->client()->id, $invoice->fresh()->client_id);
        $this->assertSame(0, ClientNote::query()->count());
    }

    public function test_a_subscription_refuses_and_the_related_contact_is_cleared(): void
    {
        $this->service->forceFill(['subscription_id' => 'sub_123'])->save();
        $this->assertInstanceOf(DisplayException::class, rescue(fn () => app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff), fn ($e) => $e, false));

        $contact = $this->from->client()->ensurePrimaryContact();
        $this->service->forceFill(['subscription_id' => null, 'contact_id' => $contact->id])->save();
        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertNull($this->service->fresh()->contact_id);
    }

    public function test_the_same_client_is_refused(): void
    {
        $this->assertInstanceOf(DisplayException::class, rescue(fn () => app(MoveToClient::class)->service($this->service, $this->from->client(), $this->staff), fn ($e) => $e, false));
    }

    public function test_a_domain_moves_with_its_contacts_copied_and_nothing_at_the_registrar(): void
    {
        $contact = $this->from->client()->ensurePrimaryContact();
        $contact->forceFill(['country' => 'GR', 'company_name' => 'Acme'])->save();
        $domain = Domain::factory()->create(['client_id' => $this->from->client()->id, 'sld' => 'acme', 'tld' => 'gr', 'fqdn' => 'acme.gr',
            'status' => DomainStatus::Active, 'expires_at' => '2027-11-17', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true,
            'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id, 'registrant_handle' => 'AC1-GR', 'admin_handle' => 'AC1-GR',
            'meta' => ['contact_handles' => [$contact->id => 'AC1-GR']]]);
        $invoice = $this->invoice($this->from, [['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'acme.gr']]);

        app(MoveToClient::class)->domain($domain, $this->to->client(), $this->staff);

        $domain->refresh();
        $copy = Contact::query()->findOrFail($domain->registrant_contact_id);
        $this->assertSame([$this->to->client()->id, $this->to->client()->id, 'Acme'], [$domain->client_id, $copy->client_id, $copy->company_name]);
        $this->assertSame($copy->id, $domain->admin_contact_id, 'one copy for the roles it shared');
        $this->assertSame(['AC1-GR', 'AC1-GR'], [$domain->registrant_handle, $domain->admin_handle], 'handles kept: nothing changes at the registrar');
        $this->assertSame([$copy->id => 'AC1-GR'], $domain->meta['contact_handles']);
        $this->assertSame($this->from->client()->id, $contact->fresh()->client_id, 'the original stays with its client');
        $this->assertSame($this->to->client()->id, $invoice->fresh()->client_id);
        Http::assertNothingSent();
    }

    public function test_staff_need_the_move_permission_and_the_form_no_longer_edits_the_client(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $without = Staff::factory()->create(['role_id' => Role::create(['name' => 'Svc', 'permissions' => ['admin.services.update', 'admin.services.viewAny']])->id]);

        $this->livewireAs($without)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertActionHidden('moveToClient')
            ->assertFormFieldIsDisabled('client_id');

        $this->livewireAs($this->staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertActionVisible('moveToClient')
            ->callAction('moveToClient', data: ['to_client_id' => $this->to->client()->id]);
        $this->assertSame($this->to->client()->id, $this->service->fresh()->client_id);
    }

    public function test_the_domain_page_moves_it_too(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = Domain::factory()->create(['client_id' => $this->from->client()->id, 'sld' => 'acme', 'tld' => 'com', 'fqdn' => 'acme.com',
            'status' => DomainStatus::Active, 'expires_at' => '2027-11-17', 'currency_code' => 'EUR', 'years' => 1]);

        $this->livewireAs($this->staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction('moveToClient', data: ['to_client_id' => $this->to->client()->id]);

        $this->assertSame($this->to->client()->id, $domain->fresh()->client_id);
    }

    /** Code review 3: an upgrade's line moves (never copied) and the upgrade follows it, or its shortfall bills it again. */
    public function test_a_split_moves_the_upgrade_with_its_line(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 5]);
        $invoice = $this->invoice($this->from, [['reference_type' => Service::class, 'reference_id' => $other->id]]);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $this->service->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id, 'invoice_id' => $invoice->id]);
        $line = $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'quantity' => 1, 'price' => 3, 'description' => 'Upgrade']);

        $result = app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertSame($result['split'][$invoice->id], (int) $upgrade->fresh()->invoice_id);
        $this->assertSame($result['split'][$invoice->id], (int) $line->fresh()->invoice_id, 'the same line, moved');
    }

    /** Code review 4: a discount that stays with another item never leaves the first client less than nothing. */
    public function test_a_split_never_leaves_a_negative_invoice(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 0]);
        $invoice = $this->invoice($this->from, [$this->serviceLine(), ['reference_type' => Service::class, 'reference_id' => $other->id, 'price' => 0], ['price' => -4, 'description' => 'Discount']]);

        $this->assertInstanceOf(DisplayException::class, rescue(fn () => app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff), fn ($e) => $e, false));

        $this->assertSame([$this->from->client()->id, 3], [$this->service->fresh()->client_id, $invoice->items()->count()]);
    }

    /** Code review 5: an order's own invoice goes with its order when the order is only this item's. */
    public function test_the_order_goes_with_its_invoice_when_it_is_only_this_item(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine()]);
        $order = Order::query()->create(['client_id' => $this->from->client()->id, 'currency_code' => 'EUR']);
        Order::query()->whereKey($order->id)->update(['invoice_id' => $invoice->id]);
        $this->service->forceFill(['order_id' => $order->id])->save();

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertSame([$this->to->client()->id, $this->to->client()->id], [(int) $order->fresh()->client_id, (int) $invoice->fresh()->client_id]);
    }

    /** Code review 9: a cancellation the first client asked for is not handed to the new one. */
    public function test_a_pending_cancellation_refuses(): void
    {
        ServiceCancellation::query()->create(['service_id' => $this->service->id, 'reason' => 'No longer needed', 'type' => 'end_of_period']);

        $this->assertInstanceOf(DisplayException::class, rescue(fn () => app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff), fn ($e) => $e, false));
        $this->assertSame($this->from->client()->id, $this->service->fresh()->client_id);
    }

    /** A free item on an invoice with others moves too: its own invoice of nothing is closed (adversarial R2, note). */
    public function test_a_free_item_on_a_shared_invoice_moves_and_its_invoice_is_closed(): void
    {
        $other = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 5]);
        $invoice = $this->invoice($this->from, [$this->serviceLine(['price' => 0]), ['reference_type' => Service::class, 'reference_id' => $other->id, 'price' => 5]]);

        $result = app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);

        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_PAID], [$invoice->fresh()->status, Invoice::query()->findOrFail($result['split'][$invoice->id])->status]);
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Code review of PR 2a (feat/clients): a login with a live grant on two accounts. The client area pages that the
 * policy opens for account B (by URL) must use B's saved payment methods, not those of the current account A.
 */
class ClientSplitCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Client, 2: Client} the login, its own (current) account A, a granted account B */
    private function loginWithTwoAccounts(): array
    {
        $user = User::factory()->create();
        $own = $user->client();
        $other = Client::factory()->ownedBy()->create();
        ClientUser::query()->create(['client_id' => $other->id, 'user_id' => $user->id, 'granted_at' => now()]);

        return [$user, $own, $other];
    }

    private function agreementOf(Client $client, string $ref): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);

        return BillingAgreement::create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal',
            'external_reference' => $ref, 'type' => 'paypal']);
    }

    public function test_a_service_of_another_account_does_not_take_the_current_accounts_payment_method(): void
    {
        [$user, $own, $other] = $this->loginWithTwoAccounts();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $other->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => now()->addMonth(),
        ]);
        $ownCard = $this->agreementOf($own, 'VAULT-OWN');

        $this->actingAs($user);
        $this->assertSame($own->id, CurrentClient::id());
        $this->assertTrue(Gate::forUser($user)->allows('view', $service)); // the policy opens B's service

        // Decision 6 (PR C2): the method is chosen per account, on the current one's «Payment methods».
        try {
            Livewire::test(PaymentMethods::class)->call('makeDefault', $ownCard->ulid);
        } catch (\Throwable) {
            // refusing is fine
        }

        $this->assertNotSame($ownCard->id, (int) $other->fresh()->default_billing_agreement_id,
            "account B's services were set to be renewed with account A's saved card");
    }

    public function test_an_invoice_of_another_account_lists_that_accounts_payment_methods(): void
    {
        [$user, $own, $other] = $this->loginWithTwoAccounts();
        $invoice = Invoice::factory()->create(['client_id' => $other->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $ownCard = $this->agreementOf($own, 'VAULT-OWN');
        $otherCard = $this->agreementOf($other, 'VAULT-OTHER');

        $this->actingAs($user);
        $this->assertTrue(Gate::forUser($user)->allows('view', $invoice));

        $methods = Livewire::test(InvoiceShow::class, ['invoice' => $invoice])->instance()->savedPaymentMethods()->pluck('id')->all();

        $this->assertNotContains($ownCard->id, $methods, "account A's card is offered to pay account B's invoice");
        $this->assertContains($otherCard->id, $methods);
    }

    public function test_login_as_client_lands_on_that_client(): void
    {
        // The owner owns two accounts (the admin API can open a second one for an existing user).
        $owner = User::factory()->create();
        $first = $owner->client();
        $second = Client::openFor($owner);
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($owner);
        // What EditClient's «Login as client» does on $second (the author's fix records the account).
        EditUser::impersonate($owner, $second);
        app(CurrentClient::class)->forget();

        $this->assertSame($second->id, CurrentClient::id(), 'impersonating from account #' . $second->id . ' shows account #' . CurrentClient::id());
    }
}

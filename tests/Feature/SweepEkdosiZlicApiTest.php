<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sweep 2026-11-07, area ekdosi-zlic-api: failing tests for what the adversarial verifier confirmed.
 */
class SweepEkdosiZlicApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private function licensedService(): Service
    {
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true], 'allow_reissue' => ['1', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service);

        return $service;
    }

    private function check(string $key, string $ip): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/zlic.php', ['license' => $key, 'license_PSK' => 'TestSecret'])->getContent(), true);
    }

    /**
     * EKDOSI-ZLIC-API-1: «Edit license» is a Textarea; a Valid IPs field cleared down to a newline is stored as "\n".
     * PHP's trim calls it unbound, SQL's TRIM (spaces only) does not: the bind matches no row and every check from the
     * right server, with the right PSK, answers «IP Address Invalid», for good.
     */
    public function test_a_valid_ip_left_as_a_newline_still_binds_on_the_next_check(): void
    {
        $this->licensedService();
        $license = License::query()->sole();
        $license->update(['valid_ip' => "\n"]);

        $answer = $this->check($license->license_key, '198.51.100.7');

        $this->assertSame('Active', $answer['status'], 'an empty list binds on the next check: ' . json_encode($answer));
        $this->assertSame('198.51.100.7', License::query()->sole()->valid_ip);
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /**
     * EKDOSI-ZLIC-API-3: mark's invoice_id goes through FILTER_VALIDATE_INT, which reads JSON true as 1 and 7.0 as 7: a
     * fiscal MARK lands on invoice #1 (add_payment was hardened against exactly this, ADV-4 of #464).
     */
    public function test_mark_refuses_a_boolean_or_float_invoice_id(): void
    {
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', '100.00', transactionId: 'TX-1');
        // The id as a float of the same value (7.0 for 7): JSON keeps the «.0».
        $float = json_decode('{"op":"mark","invoice_id":' . $invoice->id . '.0,"mark":"400001234567890"}', true);
        $this->assertIsFloat($float['invoice_id']);

        $this->bridge($float)->assertStatus(400);
        $this->bridge(['op' => 'mark', 'invoice_id' => true, 'mark' => '400001234567891'])->assertStatus(400);
        $this->assertSame(0, EkdosiDocument::query()->count(), 'no MARK on an invoice the caller never named');
    }

    /**
     * EKDOSI-ZLIC-API-5: a logged-in customer is told, by where clientarea.php sends them, whether another customer's
     * service or invoice id exists.
     */
    public function test_a_logged_in_customer_is_not_told_whether_another_customers_ids_exist(): void
    {
        $other = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $other->id]);
        $invoice = Invoice::factory()->create(['client_id' => $other->id]);
        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->loginUser($user));

        $this->assertSame(route('services'), $this->get("/clients/clientarea.php?action=productdetails&id={$service->id}")->assertStatus(301)->headers->get('Location'));
        $this->assertSame(route('invoices'), $this->get("/clients/viewinvoice.php?id={$invoice->id}")->assertStatus(301)->headers->get('Location'));
    }
}
